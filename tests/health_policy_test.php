<?php
require_once __DIR__ . '/../plugin/scripts/AmneziaWG/amneziawg-health-policy.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ping = awg_health_ping_command('10.0.11.1', '10.0.11.254');
check(str_contains($ping, '-4 -n -c 2 -o -W 1500'), 'two tunnel-bound ICMP attempts with early success');
check(str_contains($ping, "-S '10.0.11.1' '10.0.11.254'"), 'explicit AWG source address and tunnel target');
check(!str_contains($ping, 'http'), 'no public HTTPS endpoint required');
$injection = awg_health_ping_command('10.0.11.1', '10.0.11.254;touch /tmp/unwanted');
check(str_contains($injection, "'10.0.11.254;touch /tmp/unwanted'"), 'target is shell escaped');

check(!awg_health_watchdog_restart_eligible([
    'status' => 'offline', 'failure_kind' => 'network',
    'consecutive_failures' => 100, 'consecutive_local_failures' => 0,
]), 'network timeouts must not restart an otherwise running AWG');
check(!awg_health_watchdog_restart_eligible([
    'status' => 'offline', 'failure_kind' => 'config',
    'consecutive_failures' => 100, 'consecutive_local_failures' => 0,
]), 'configuration errors cannot be fixed by tunnel restart');
check(!awg_health_watchdog_restart_eligible([
    'status' => 'offline', 'failure_kind' => 'local',
    'consecutive_failures' => 5, 'consecutive_local_failures' => 2,
]), 'require three consecutive local failures, not mixed network/local');
check(awg_health_watchdog_restart_eligible([
    'status' => 'offline', 'failure_kind' => 'local',
    'consecutive_failures' => 3, 'consecutive_local_failures' => 3,
]), 'three consecutive local runtime failures allow restart');
check(!awg_health_watchdog_restart_eligible([
    'status' => 'waiting', 'failure_kind' => 'local',
    'consecutive_local_failures' => 12,
]), 'waiting for routing must not restart');
check(!awg_health_watchdog_restart_eligible([
    'status' => 'offline', 'consecutive_failures' => 10,
]), 'old caches without failure_kind fail closed');

$health = file_get_contents(__DIR__ . '/../plugin/scripts/AmneziaWG/amneziawg-health.php');
$watchdog = file_get_contents(__DIR__ . '/../plugin/scripts/AmneziaWG/amneziawg-watchdog.php');
$api = file_get_contents(__DIR__ . '/../plugin/mvc/app/controllers/OPNsense/AmneziaWG/Api/ServiceController.php');
$actions = file_get_contents(__DIR__ . '/../plugin/service/conf/actions.d/actions_amneziawg.conf');
check(str_contains($health, "'.lock.v1', 'ce'"), 'production lock must close on exec');
check(str_contains($health, 'LOCK_EX | LOCK_NB'), 'production checks must skip concurrent producers');
check(str_contains($health, "if ($"."probeOnly) {"), 'manual test bypasses cache and native gateway sync');
check(str_contains($health, "if (!$"."probeOnly &&"), 'manual test avoids route manipulation');
check(str_contains($health, 'consecutive_local_failures'), 'local failure counter is tracked separately');
check(str_contains($health, 'awg_health_ping_command('), 'active ping comes from tested helper');
check(str_contains($watchdog, 'awg_health_watchdog_restart_eligible('), 'watchdog observes policy helper');
check(str_contains($watchdog, "'busy'"), 'busy health producers are not treated as tunnel failure');
check(str_contains($api, "amneziawg health_test "), 'manual GUI uses read-only action');
check(str_contains($actions, "[health_test]"), 'configd action for read-only health test exists');
check(str_contains($actions, 'amneziawg-health.php --probe-only'), 'read-only action passes probe-only flag');
echo "OK: AWG health policy regression tests passed\n";
