<?php
/**
 * Pure health-check and watchdog policy helpers.
 *
 * Keep the active AWG dataplane probe tunnel-bound: it is a source-bound ICMP
 * request to the configured remote tunnel gateway, not arbitrary public HTTPS.
 */
const AWG_HEALTH_TIMEOUT_MS = 1500;
const AWG_HEALTH_PING_COUNT = 2;

function awg_health_ping_command(string $source, string $target): string
{
    // FreeBSD ping: -o exits on the first reply; -c 2 allows a second attempt
    // after isolated packet loss without delaying a normally healthy probe.
    return '/sbin/ping -4 -n -c ' . AWG_HEALTH_PING_COUNT
        . ' -o -W ' . AWG_HEALTH_TIMEOUT_MS
        . ' -S ' . escapeshellarg($source) . ' ' . escapeshellarg($target)
        . ' 2>&1';
}

function awg_health_watchdog_restart_eligible(array $health): bool
{
    // Three *consecutive local* runtime failures are required. ICMP loss,
    // broken remote routes and missing gateway configuration are not fixed by
    // restarting the local kernel tunnel.
    return ($health['status'] ?? '') === 'offline'
        && ($health['failure_kind'] ?? '') === 'local'
        && (int)($health['consecutive_local_failures'] ?? 0) >= 3;
}
