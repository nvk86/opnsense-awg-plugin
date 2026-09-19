#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

const AWG_HEALTH_TIMEOUT_MS = 1500;

function awg_health_output(array $data, int $rc = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($rc);
}

function awg_health_valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function awg_health_cache_path(string $uuid): string
{
    return '/var/run/amneziawg-health-' . $uuid . '.json';
}

function awg_health_read_cache(string $uuid): array
{
    $path = awg_health_cache_path($uuid);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function awg_health_write_cache(string $uuid, array $data): void
{
    $path = awg_health_cache_path($uuid);
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @chmod($tmp, 0644);
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

function awg_health_find_instance($cfg, string $uuid)
{
    foreach (($cfg->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
        if ((string)$inst['uuid'] === $uuid) {
            return $inst;
        }
    }
    return null;
}

function awg_health_iface($inst): string
{
    $raw = trim((string)($inst->interface_number ?? ''));
    return 'awg' . ($raw === '' ? '0' : (string)(int)$raw);
}

function awg_health_source_ipv4($inst): string
{
    $raw = trim((string)($inst->address ?? ''));
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $cidr) {
        $parts = explode('/', trim($cidr), 2);
        if (filter_var($parts[0] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (string)$parts[0];
        }
    }
    return '';
}

function awg_health_assignment($cfg, string $iface): array
{
    if (!isset($cfg->interfaces)) {
        return [];
    }
    foreach ($cfg->interfaces->children() as $key => $ifcfg) {
        if ((string)($ifcfg->if ?? '') !== $iface) {
            continue;
        }
        return [
            'key' => (string)$key,
            'descr' => (string)($ifcfg->descr ?? $key),
            'enabled' => (string)($ifcfg->enable ?? '0') === '1',
        ];
    }
    return [];
}

function awg_health_native_gateway(string $assignment, string $preferred = ''): array
{
    if ($assignment === '') {
        return ['name' => '', 'address' => '', 'error' => 'AWG interface is not assigned in OPNsense'];
    }
    try {
        $model = new OPNsense\Routing\Gateways();
        $rows = [];
        foreach ($model->gatewayIterator() as $row) {
            if (($row['interface'] ?? '') !== $assignment || ($row['ipprotocol'] ?? 'inet') !== 'inet') {
                continue;
            }
            $address = trim((string)($row['gateway'] ?? ''));
            if ($address === '' || strtolower($address) === 'dynamic'
                || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            if ($preferred !== '' && $address === $preferred) {
                return ['name' => (string)($row['name'] ?? ''), 'address' => $address, 'error' => ''];
            }
            $rows[] = $row;
        }
        if (count($rows) === 1) {
            return [
                'name' => (string)($rows[0]['name'] ?? ''),
                'address' => trim((string)($rows[0]['gateway'] ?? '')),
                'error' => '',
            ];
        }
        if (empty($rows)) {
            return ['name' => '', 'address' => '', 'error' => 'No static IPv4 gateway exists on the assigned AWG interface'];
        }
        return ['name' => '', 'address' => '',
            'error' => 'Multiple static IPv4 gateways exist; set Health Probe Target explicitly'];
    } catch (Throwable $e) {
        return ['name' => '', 'address' => '', 'error' => 'Unable to inspect native gateways'];
    }
}

function awg_health_latest_handshake(string $iface): int
{
    $out = [];
    $rc = 1;
    exec('/usr/local/bin/awg show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return 0;
    }
    $latest = 0;
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line));
        $ts = (int)($parts[1] ?? 0);
        if ($ts > $latest) {
            $latest = $ts;
        }
    }
    return $latest;
}

function awg_health_sync_gateway(string $uuid, bool $online, int $failures, string $status): void
{
    $script = '/usr/local/opnsense/scripts/AmneziaWG/amneziawg-gateway-sync.php';
    if (!is_file($script)) {
        return;
    }
    if ($status === 'stopped') {
        $state = 'stopped';
    } elseif ($online) {
        $state = 'online';
    } elseif ($failures >= 3) {
        $state = 'offline';
    } else {
        $state = 'unknown';
    }
    @exec('/usr/local/bin/php ' . escapeshellarg($script) . ' '
        . escapeshellarg($uuid) . ' ' . escapeshellarg($state) . ' >/dev/null 2>&1');
}

function awg_health_update(string $uuid, bool $online, string $status, string $message, ?int $latencyMs, array $extra = []): array
{
    $old = awg_health_read_cache($uuid);
    $now = time();
    $failures = $status === 'stopped' ? 0 : ($online ? 0 : ((int)($old['consecutive_failures'] ?? 0) + 1));
    $state = array_merge([
        'online' => $online,
        'status' => $status,
        'message' => $message,
        'latency_ms' => $latencyMs,
        'checked_at' => $now,
        'checked_at_iso' => date('c', $now),
        'consecutive_failures' => $failures,
        'last_ok' => $online ? $now : ($old['last_ok'] ?? null),
        'last_failure' => (!$online && $status !== 'stopped') ? $now : ($old['last_failure'] ?? null),
        'last_restart' => $old['last_restart'] ?? null,
    ], $extra);
    awg_health_write_cache($uuid, $state);
    awg_health_sync_gateway($uuid, $online, $failures, $status);
    return $state;
}

$uuid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if (!awg_health_valid_uuid($uuid)) {
    awg_health_output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
}

$cfg = OPNsense\Core\Config::getInstance()->object();
$inst = awg_health_find_instance($cfg, $uuid);
if ($inst === null) {
    awg_health_output(['result' => 'failed', 'message' => 'Instance not found'], 1);
}

$iface = awg_health_iface($inst);
$globalEnabled = (string)($cfg->OPNsense->amneziawg->general->enabled ?? '0') === '1';
$instanceEnabled = (string)($inst->enabled ?? '0') === '1';
$manualStopped = is_file('/var/run/amneziawg_stopped.flag')
    || is_file('/var/run/amneziawg_stopped_' . $iface . '.flag');

if (!$globalEnabled || !$instanceEnabled || $manualStopped) {
    $state = awg_health_update(
        $uuid,
        false,
        'stopped',
        $manualStopped ? 'Client is manually stopped' : 'Client is disabled',
        null,
        ['interface' => $iface]
    );
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$healthEnabled = (string)($inst->health_monitor ?? '0') === '1'
    || (string)($inst->gateway_health_sync ?? '0') === '1';
if (!$healthEnabled) {
    awg_health_output([
        'result' => 'skipped',
        'status' => 'disabled',
        'online' => false,
        'message' => 'Health Monitor is disabled for this client',
        'interface' => $iface,
    ]);
}

$source = awg_health_source_ipv4($inst);
if ($source === '') {
    $state = awg_health_update($uuid, false, 'offline',
        'No IPv4 tunnel address is configured for the health probe', null,
        ['interface' => $iface]);
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$ifOut = [];
$ifRc = 1;
exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $ifOut, $ifRc);
$ifText = implode("\n", $ifOut);
$ifaceUp = $ifRc === 0
    && preg_match('/flags=\S+<([^>]+)>/', $ifText, $fm)
    && strpos($fm[1], 'UP') !== false;

$showOut = [];
$showRc = 1;
exec('/usr/local/bin/awg show ' . escapeshellarg($iface) . ' 2>/dev/null', $showOut, $showRc);
if (!$ifaceUp || $showRc !== 0) {
    $state = awg_health_update($uuid, false, 'offline',
        'AWG runtime is not ready on ' . $iface, null,
        ['interface' => $iface, 'source' => $source]);
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$assignment = awg_health_assignment($cfg, $iface);
$preferred = trim((string)($inst->health_target ?? ''));
if ($preferred !== '' && filter_var($preferred, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    $state = awg_health_update($uuid, false, 'offline',
        'Health Probe Target is not a valid IPv4 address', null,
        ['interface' => $iface, 'source' => $source, 'target' => $preferred]);
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$gateway = awg_health_native_gateway((string)($assignment['key'] ?? ''), $preferred);
$target = $preferred !== '' ? $preferred : (string)$gateway['address'];
if ($target === '') {
    $state = awg_health_update($uuid, false, 'offline',
        $gateway['error'] ?: 'Unable to resolve a health probe target', null,
        ['interface' => $iface, 'source' => $source]);
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$start = microtime(true);
$out = [];
$rc = 1;
$cmd = '/sbin/ping -4 -n -c 1 -o -W ' . AWG_HEALTH_TIMEOUT_MS
    . ' -S ' . escapeshellarg($source) . ' ' . escapeshellarg($target) . ' 2>&1';
exec($cmd, $out, $rc);
$latency = null;
if ($rc === 0) {
    foreach ($out as $line) {
        if (preg_match('/time[=<]([0-9.]+)\s*ms/i', $line, $m)) {
            $latency = (int)round((float)$m[1]);
            break;
        }
    }
    if ($latency === null) {
        $latency = (int)round((microtime(true) - $start) * 1000);
    }
}

$latestHandshake = awg_health_latest_handshake($iface);
$extra = [
    'interface' => $iface,
    'source' => $source,
    'target' => $target,
    'native_gateway' => (string)$gateway['name'],
    'native_gateway_address' => (string)$gateway['address'],
    'latest_handshake' => $latestHandshake,
];

if ($rc !== 0) {
    $state = awg_health_update(
        $uuid,
        false,
        'offline',
        'No ICMP reply from ' . $target . ' through ' . $iface,
        null,
        $extra
    );
    awg_health_output(['result' => 'failed'] + $state, 0);
}

$message = 'Online via ' . $iface . ' (' . $target . ', ' . $latency . ' ms)';
$state = awg_health_update($uuid, true, 'online', $message, $latency, $extra);
awg_health_output(['result' => 'ok'] + $state, 0);
