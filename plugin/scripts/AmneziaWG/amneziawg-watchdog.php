#!/usr/local/bin/php
<?php

// AmneziaWG watchdog and health scheduler.
// Called every minute via configctl amneziawg watchdog.
//
// Runtime recovery is controlled by the global Watchdog switch.
// Active per-client health probes and Gateway Health Sync continue to run
// independently when enabled on a client, so native gateway failover does not
// depend on automatic tunnel restart being enabled.

require_once('/usr/local/etc/inc/config.inc');

define('AWG_PID_FILE', '/var/run/amneziawg.pid');
define('AWG_STOPPED_FLAG', '/var/run/amneziawg_stopped.flag');
define('AWG_HEALTH_RESTART_COOLDOWN', 600);

function wdg_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    file_put_contents('/var/log/amneziawg.log', "[$ts] WATCHDOG: $msg\n", FILE_APPEND | LOCK_EX);
}

function wdg_valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function wdg_health_cache_path(string $uuid): string
{
    return '/var/run/amneziawg-health-' . $uuid . '.json';
}

function wdg_mark_restart(string $uuid): void
{
    $path = wdg_health_cache_path($uuid);
    if (!is_file($path)) {
        return;
    }
    $state = json_decode((string)@file_get_contents($path), true);
    if (!is_array($state)) {
        return;
    }
    $state['last_restart'] = time();
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents(
        $tmp,
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    ) !== false) {
        @chmod($tmp, 0644);
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

$config = OPNsense\Core\Config::getInstance()->object();
$watchdogEnabled = (string)($config->OPNsense->amneziawg->general->watchdog ?? '0') === '1';
$serviceEnabled = (string)($config->OPNsense->amneziawg->general->enabled ?? '0') === '1';
$serviceStopped = file_exists(AWG_STOPPED_FLAG);

$clientRows = [];
$expected = [];

$container = $config->OPNsense->amneziawg->instances ?? null;
if (isset($container) && isset($container->instance)) {
    foreach ($container->instance as $inst) {
        if ((string)($inst->enabled ?? '0') !== '1') {
            continue;
        }
        $uuid = (string)$inst['uuid'];
        if (!wdg_valid_uuid($uuid)) {
            continue;
        }
        $ifnum = trim((string)($inst->interface_number ?? ''));
        $iface = 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
        $manualStopped = file_exists('/var/run/amneziawg_stopped_' . $iface . '.flag');
        if (!$manualStopped) {
            $expected[] = $iface;
        }
        $clientRows[] = [
            'uuid' => $uuid,
            'iface' => $iface,
            'manual_stopped' => $manualStopped,
            'health_monitor' => (string)($inst->health_monitor ?? '0') === '1',
            'gateway_sync' => (string)($inst->gateway_health_sync ?? '0') === '1',
        ];
    }
}

$servers = $config->OPNsense->amneziawg->servers ?? null;
if (isset($servers) && isset($servers->server)) {
    foreach ($servers->server as $srv) {
        if ((string)($srv->enabled ?? '0') !== '1') {
            continue;
        }
        $ifnum = trim((string)($srv->interface_number ?? ''));
        $iface = 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
        if (!file_exists('/var/run/amneziawg_stopped_' . $iface . '.flag')) {
            $expected[] = $iface;
        }
    }
}
$expected = array_values(array_unique($expected));

$kmodLoaded = false;
exec('/sbin/kldstat -q -m if_awg 2>/dev/null', $_, $kmodRc);
$kmodLoaded = $kmodRc === 0;

// Existing runtime watchdog behavior: repair only when the operator enabled
// Watchdog, the service itself is enabled, and it was not manually stopped.
if ($watchdogEnabled && $serviceEnabled && !$serviceStopped) {
    if (!$kmodLoaded) {
        wdg_log('if_awg kernel module not loaded — runtime recovery skipped');
    } elseif (!empty($expected)) {
        $ifOut = [];
        exec('/sbin/ifconfig -l', $ifOut);
        $existing = explode(' ', trim($ifOut[0] ?? ''));
        $missing = array_values(array_diff($expected, $existing));

        if (!empty($missing)) {
            wdg_log('Tunnel(s) down: ' . implode(',', $missing) . ' — starting individually...');
            $backend = new OPNsense\Core\Backend();
            foreach ($missing as $iface) {
                $output = trim((string)$backend->configdRun('amneziawg start_instance ' . $iface));
                wdg_log('start_instance ' . $iface . ' result: ' . substr($output, 0, 200));
            }
        } else {
            $pidAlive = false;
            if (file_exists(AWG_PID_FILE)) {
                $pid = (int)trim((string)file_get_contents(AWG_PID_FILE));
                if ($pid > 0) {
                    if (function_exists('posix_kill')) {
                        $pidAlive = posix_kill($pid, 0);
                    } else {
                        exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $_, $pidRc);
                        $pidAlive = $pidRc === 0;
                    }
                }
            }
            if (!$pidAlive) {
                wdg_log('Sentinel PID not alive, repairing...');
                $backend = new OPNsense\Core\Backend();
                $output = trim((string)$backend->configdRun('amneziawg sentinel_repair'));
                wdg_log('Sentinel repair result: ' . substr($output, 0, 200));
            }
        }
    }
}

$healthScript = '/usr/local/opnsense/scripts/AmneziaWG/amneziawg-health.php';
$backend = null;
$healthSummaries = [];

foreach ($clientRows as $row) {
    if (!$row['health_monitor'] && !$row['gateway_sync']) {
        continue;
    }

    $out = [];
    $rc = 1;
    exec('/usr/local/bin/php ' . escapeshellarg($healthScript) . ' '
        . escapeshellarg($row['uuid']) . ' 2>/dev/null', $out, $rc);
    $decoded = json_decode(implode("\n", $out), true);

    if (!is_array($decoded)) {
        wdg_log('Health probe [' . $row['iface'] . '] returned invalid output');
        continue;
    }

    $status = (string)($decoded['status'] ?? 'unknown');
    $failures = (int)($decoded['consecutive_failures'] ?? 0);
    $message = (string)($decoded['message'] ?? '');
    $healthSummaries[] = $row['iface'] . ':' . $status;

    if ($status === 'online' || $status === 'waiting') {
        continue;
    }

    if ($status !== 'stopped') {
        wdg_log('Health [' . $row['iface'] . ']: ' . $status
            . ' (' . $message . '), failures=' . $failures);
    }

    if (!$watchdogEnabled || !$serviceEnabled || $serviceStopped
        || $row['manual_stopped'] || !$kmodLoaded || $failures < 3) {
        continue;
    }

    $lastRestart = (int)($decoded['last_restart'] ?? 0);
    if ($lastRestart > 0 && (time() - $lastRestart) < AWG_HEALTH_RESTART_COOLDOWN) {
        continue;
    }

    if ($backend === null) {
        $backend = new OPNsense\Core\Backend();
    }
    wdg_log('Health threshold reached for ' . $row['iface'] . ' — restarting this tunnel...');
    $restart = trim((string)$backend->configdRun('amneziawg restart_instance ' . $row['iface']));
    wdg_log('restart_instance ' . $row['iface'] . ' result: ' . substr($restart, 0, 200));
    if (stripos($restart, 'OK') !== false && stripos($restart, 'ERROR') === false) {
        wdg_mark_restart($row['uuid']);
    }
}

// Reconcile stale ownership even when no client probe ran (for example after
// disabling/removing a client or turning Gateway Health Sync off).
$syncScript = '/usr/local/opnsense/scripts/AmneziaWG/amneziawg-gateway-sync.php';
if (is_file($syncScript)) {
    @exec('/usr/local/bin/php ' . escapeshellarg($syncScript) . ' reconcile >/dev/null 2>&1');
}

echo empty($healthSummaries) ? "OK\n" : (implode(' ', $healthSummaries) . "\n");
