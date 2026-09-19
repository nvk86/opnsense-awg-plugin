#!/usr/local/bin/php
<?php

set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once('config.inc');

const AWG_GS_REGISTRY = '/usr/local/etc/amnezia/gateway-health-sync.json';

function awg_gs_output(array $data, int $rc = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($rc);
}

function awg_gs_valid_uuid(string $uuid): bool
{
    return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid);
}

function awg_gs_registry_read(): array
{
    if (!is_file(AWG_GS_REGISTRY)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents(AWG_GS_REGISTRY), true);
    return is_array($data) ? $data : [];
}

function awg_gs_registry_write(array $data): bool
{
    $dir = dirname(AWG_GS_REGISTRY);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    @chmod($dir, 0700);
    $tmp = AWG_GS_REGISTRY . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, AWG_GS_REGISTRY)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function awg_gs_find_instance($cfg, string $uuid)
{
    foreach (($cfg->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
        if ((string)$inst['uuid'] === $uuid) {
            return $inst;
        }
    }
    return null;
}

function awg_gs_iface_for_instance($inst): string
{
    $raw = trim((string)($inst->interface_number ?? ''));
    return 'awg' . ($raw === '' ? '0' : (string)(int)$raw);
}

function awg_gs_assignment_for_iface($cfg, string $iface): array
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
            'gateway_interface' => (string)($ifcfg->gateway_interface ?? '0') === '1',
        ];
    }
    return [];
}

function awg_gs_gateway_candidates(OPNsense\Routing\Gateways $model, string $assignment): array
{
    $rows = [];
    foreach ($model->gatewayIterator() as $row) {
        if (($row['interface'] ?? '') !== $assignment) {
            continue;
        }
        if (($row['ipprotocol'] ?? 'inet') !== 'inet') {
            continue;
        }
        $address = trim((string)($row['gateway'] ?? ''));
        if ($address === '' || strtolower($address) === 'dynamic'
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function awg_gs_select_gateway(OPNsense\Routing\Gateways $model, string $assignment, string $healthTarget = ''): array
{
    $candidates = awg_gs_gateway_candidates($model, $assignment);
    if ($healthTarget !== '') {
        foreach ($candidates as $row) {
            if (trim((string)($row['gateway'] ?? '')) === $healthTarget) {
                return ['row' => $row, 'error' => ''];
            }
        }
    }
    if (count($candidates) === 1) {
        return ['row' => $candidates[0], 'error' => ''];
    }
    if (empty($candidates)) {
        return ['row' => null, 'error' => 'No static IPv4 gateway exists on the assigned AWG interface'];
    }
    return ['row' => null, 'error' => 'Multiple static IPv4 gateways exist on the assigned AWG interface; set Health Probe Target to the intended gateway address'];
}

function awg_gs_find_persisted_gateway(OPNsense\Routing\Gateways $model, array $record): ?array
{
    $trackedUuid = trim((string)($record['uuid'] ?? ''));
    if ($trackedUuid !== '') {
        foreach ($model->gatewayIterator() as $row) {
            if (($row['uuid'] ?? '') === $trackedUuid) {
                return $row;
            }
        }
        return null;
    }

    $trackedName = trim((string)($record['name'] ?? ''));
    if ($trackedName !== '') {
        foreach ($model->gatewayIterator() as $row) {
            if (($row['name'] ?? '') === $trackedName) {
                return $row;
            }
        }
    }
    return null;
}

function awg_gs_refresh_gateway_watcher(): array
{
    // OPNsense overlays dpinger_status() with /tmp/gateways.status whenever
    // the live report has loss='~'. After native monitoring is disabled, an
    // old watcher entry can therefore mask a newly persisted Force Down state.
    // Restart only the Gateway Watcher once when we adopt the gateway so its
    // cache is rebuilt without the now-unmonitored AWG member.
    $out = [];
    $rc = 1;
    exec('/usr/local/sbin/pluginctl -c monitor ' . escapeshellarg(':watcher:') . ' 2>&1', $out, $rc);

    if ($rc === 0) {
        // gateway_watcher.php unlinks this file on startup. Remove any residual
        // cache as well for the no-other-dpinger case; the restarted watcher
        // will repopulate it from fresh state.
        @unlink('/tmp/gateways.status');
    }

    return [
        'ok' => $rc === 0,
        'message' => trim(implode("\n", $out)),
        'rc' => $rc,
    ];
}

function awg_gs_alarm(string $gateway): array
{
    if ($gateway === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/D', $gateway)) {
        return ['ok' => false, 'message' => 'Invalid native gateway name', 'rc' => 1];
    }
    $out = [];
    $rc = 1;
    exec('/usr/local/bin/flock -n -E 0 -o /tmp/filter_reload_gateway.lock /usr/local/etc/rc.routing_configure alarm '
        . escapeshellarg($gateway) . ' 2>&1', $out, $rc);
    return ['ok' => $rc === 0, 'message' => trim(implode("\n", $out)), 'rc' => $rc];
}

function awg_gs_cleanup_probe_route(string $uuid): void
{
    $path = '/var/run/amneziawg-health-route-' . $uuid . '.json';
    if (!is_file($path)) {
        return;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    $target = trim((string)($data['target'] ?? ''));
    $iface = trim((string)($data['interface'] ?? ''));
    if ($target !== '' && $iface !== ''
        && filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && preg_match('/^awg\d{1,2}$/D', $iface)) {
        @exec('/sbin/route delete -host ' . escapeshellarg($target)
            . ' -iface ' . escapeshellarg($iface) . ' >/dev/null 2>&1');
    }
    @unlink($path);
}

function awg_gs_release_record(string $uuid, array $record): array
{
    awg_gs_cleanup_probe_route($uuid);
    $model = new OPNsense\Routing\Gateways();
    $row = awg_gs_find_persisted_gateway($model, $record);
    $name = (string)($record['name'] ?? '');

    if ($row === null) {
        $registry = awg_gs_registry_read();
        unset($registry[$uuid]);
        awg_gs_registry_write($registry);
        return ['result' => 'ok', 'instance' => $uuid, 'gateway' => $name, 'changed' => false,
            'message' => 'Tracked gateway no longer exists'];
    }

    $gwUuid = (string)($row['uuid'] ?? '');
    $name = (string)($row['name'] ?? $name);
    if ($gwUuid === '') {
        return ['result' => 'warning', 'instance' => $uuid, 'gateway' => $name,
            'message' => 'Tracked gateway has no UUID; ownership state was not changed'];
    }

    $original = !empty($record['original_force_down']) ? '1' : '0';
    $current = !empty($row['force_down']) && (string)$row['force_down'] !== '0';
    $changed = $current !== ($original === '1');

    if ($changed) {
        $model->createOrUpdateGateway(['force_down' => $original], $gwUuid);
        OPNsense\Core\Config::getInstance()->save();
    }

    $alarm = $name !== '' ? awg_gs_alarm($name) : ['ok' => true, 'message' => '', 'rc' => 0];
    if ($alarm['ok']) {
        $registry = awg_gs_registry_read();
        unset($registry[$uuid]);
        awg_gs_registry_write($registry);
    }

    return [
        'result' => $alarm['ok'] ? 'ok' : 'warning',
        'instance' => $uuid,
        'gateway' => $name,
        'force_down' => $original === '1',
        'changed' => $changed,
        'released' => true,
        'alarm' => $alarm,
        'message' => $alarm['ok']
            ? 'Gateway Health Sync released; original Force Down restored'
            : 'Force Down restored but routing alarm failed',
    ];
}

function awg_gs_health_state(string $uuid): string
{
    $path = '/var/run/amneziawg-health-' . $uuid . '.json';
    if (!is_file($path)) {
        return 'unknown';
    }
    $health = json_decode((string)@file_get_contents($path), true);
    if (!is_array($health)) {
        return 'unknown';
    }
    if (($health['status'] ?? '') === 'stopped') {
        return 'stopped';
    }
    $checked = (int)($health['checked_at'] ?? 0);
    if ($checked <= 0) {
        return 'unknown';
    }
    if ((time() - $checked) > 150) {
        return 'stale';
    }
    if (!empty($health['online'])) {
        return 'online';
    }
    return ((int)($health['consecutive_failures'] ?? 0) >= 3) ? 'offline' : 'unknown';
}

function awg_gs_sync_one(string $uuid, string $healthState): array
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $registry = awg_gs_registry_read();
    $inst = awg_gs_find_instance($cfg, $uuid);

    if ($inst === null) {
        if (isset($registry[$uuid])) {
            return awg_gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'message' => 'Instance not found'];
    }

    $syncEnabled = (string)($inst->gateway_health_sync ?? '0') === '1';
    $monitorEnabled = (string)($inst->health_monitor ?? '0') === '1';
    if (!$syncEnabled || !$monitorEnabled) {
        if (isset($registry[$uuid])) {
            return awg_gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'message' => 'Gateway Health Sync is disabled'];
    }

    $iface = awg_gs_iface_for_instance($inst);
    $assignment = awg_gs_assignment_for_iface($cfg, $iface);
    if (empty($assignment) || empty($assignment['enabled'])) {
        if (isset($registry[$uuid])) {
            return awg_gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'interface' => $iface,
            'message' => 'Assign and enable the AWG interface in OPNsense before Gateway Health Sync'];
    }

    if (!in_array($healthState, ['online', 'offline', 'stopped', 'unknown', 'stale'], true)) {
        $healthState = 'unknown';
    }
    if ($healthState === 'unknown' || $healthState === 'stale') {
        return [
            'result' => 'ok',
            'instance' => $uuid,
            'interface' => $iface,
            'health_state' => $healthState,
            'changed' => false,
            'message' => 'Health state is inconclusive; native gateway left unchanged',
        ];
    }

    $healthTarget = trim((string)($inst->health_target ?? ''));
    $gwModel = new OPNsense\Routing\Gateways();
    $selected = awg_gs_select_gateway($gwModel, (string)$assignment['key'], $healthTarget);
    if ($selected['row'] === null) {
        if (isset($registry[$uuid])) {
            awg_gs_release_record($uuid, $registry[$uuid]);
        }
        return ['result' => 'skipped', 'instance' => $uuid, 'interface' => $iface,
            'health_state' => $healthState, 'message' => $selected['error']];
    }

    $row = $selected['row'];
    $gwUuid = (string)($row['uuid'] ?? '');
    $gwName = (string)($row['name'] ?? '');
    $gwAddress = trim((string)($row['gateway'] ?? ''));
    if ($gwUuid === '' || $gwName === '') {
        return ['result' => 'warning', 'instance' => $uuid, 'message' => 'Native gateway has no UUID or name'];
    }

    $monitorDisabled = !empty($row['monitor_disable']) && (string)$row['monitor_disable'] !== '0';
    if (!$monitorDisabled) {
        if (isset($registry[$uuid])) {
            awg_gs_release_record($uuid, $registry[$uuid]);
        }
        return [
            'result' => 'skipped',
            'instance' => $uuid,
            'gateway' => $gwName,
            'gateway_address' => $gwAddress,
            'message' => 'Disable native Gateway Monitoring before enabling plugin Gateway Health Sync',
        ];
    }

    if (isset($registry[$uuid])) {
        $trackedUuid = (string)($registry[$uuid]['uuid'] ?? '');
        if ($trackedUuid !== '' && $trackedUuid !== $gwUuid) {
            $released = awg_gs_release_record($uuid, $registry[$uuid]);
            if (($released['result'] ?? '') !== 'ok') {
                return ['result' => 'warning', 'instance' => $uuid, 'migration' => $released,
                    'message' => 'Previous gateway ownership could not be released safely'];
            }
            $registry = awg_gs_registry_read();
        }
    }

    if (!isset($registry[$uuid])) {
        $registry[$uuid] = [
            'name' => $gwName,
            'interface' => (string)$assignment['key'],
            'uuid' => $gwUuid,
            'gateway_address' => $gwAddress,
            'original_force_down' => !empty($row['force_down']) && (string)$row['force_down'] !== '0',
            'created' => false,
            'tracked_at' => time(),
            'watcher_reconciled' => false,
            'alarm_pending' => false,
        ];
        if (!awg_gs_registry_write($registry)) {
            return ['result' => 'warning', 'instance' => $uuid, 'gateway' => $gwName,
                'message' => 'Could not persist gateway ownership registry'];
        }
    }

    // Existing 2.1.0 pre-release registries do not have this flag, so
    // upgrading the test build automatically performs the one-time watcher
    // cache reconciliation before the next Force Down transition.
    $registry = awg_gs_registry_read();
    $watcherReconciled = !empty($registry[$uuid]['watcher_reconciled']);
    $watcherRefresh = ['ok' => true, 'message' => '', 'rc' => 0];
    if (!$watcherReconciled) {
        $watcherRefresh = awg_gs_refresh_gateway_watcher();
        if (!$watcherRefresh['ok']) {
            return [
                'result' => 'warning',
                'instance' => $uuid,
                'gateway' => $gwName,
                'gateway_address' => $gwAddress,
                'changed' => false,
                'watcher_refresh' => $watcherRefresh,
                'message' => 'Gateway Watcher cache could not be reconciled; Force Down was not changed',
            ];
        }
        $registry = awg_gs_registry_read();
        if (isset($registry[$uuid])) {
            $registry[$uuid]['watcher_reconciled'] = true;
            awg_gs_registry_write($registry);
        }
    }

    $desired = $healthState === 'online' ? '0' : '1';
    $currentBool = !empty($row['force_down']) && (string)$row['force_down'] !== '0';
    $desiredBool = $desired === '1';
    $changed = $currentBool !== $desiredBool;

    if ($changed) {
        $gwModel->createOrUpdateGateway(['force_down' => $desired], $gwUuid);
        OPNsense\Core\Config::getInstance()->save();
    }

    $alarm = $changed ? awg_gs_alarm($gwName) : ['ok' => true, 'message' => '', 'rc' => 0];
    $registry = awg_gs_registry_read();
    if (isset($registry[$uuid])) {
        $registry[$uuid]['alarm_pending'] = !$alarm['ok'];
        awg_gs_registry_write($registry);
    }

    return [
        'result' => $alarm['ok'] ? 'ok' : 'warning',
        'instance' => $uuid,
        'enabled' => true,
        'interface' => $iface,
        'assignment' => (string)$assignment['key'],
        'gateway' => $gwName,
        'gateway_address' => $gwAddress,
        'health_state' => $healthState,
        'force_down' => $desiredBool,
        'changed' => $changed,
        'created' => false,
        'alarm' => $alarm,
        'message' => $alarm['ok']
            ? 'Native AWG gateway Force Down synchronized'
            : 'Force Down changed but OPNsense routing alarm failed',
    ];
}

$arg1 = isset($argv[1]) ? trim((string)$argv[1]) : 'reconcile';

if ($arg1 === 'release') {
    $uuid = isset($argv[2]) ? trim((string)$argv[2]) : '';
    if (!awg_gs_valid_uuid($uuid)) {
        awg_gs_output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
    }
    $registry = awg_gs_registry_read();
    if (!isset($registry[$uuid])) {
        awg_gs_output(['result' => 'ok', 'instance' => $uuid, 'message' => 'No tracked gateway']);
    }
    awg_gs_output(awg_gs_release_record($uuid, $registry[$uuid]));
}

if ($arg1 === 'release_all') {
    $registry = awg_gs_registry_read();
    $rows = [];
    foreach ($registry as $uuid => $record) {
        $rows[$uuid] = awg_gs_release_record((string)$uuid, $record);
    }
    awg_gs_output(['result' => 'ok', 'rows' => $rows]);
}

if ($arg1 === '' || $arg1 === 'reconcile') {
    $cfg = OPNsense\Core\Config::getInstance()->object();
    $seen = [];
    $rows = [];
    foreach (($cfg->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
        $uuid = (string)$inst['uuid'];
        if (!awg_gs_valid_uuid($uuid)) {
            continue;
        }
        $seen[$uuid] = true;
        $rows[$uuid] = awg_gs_sync_one($uuid, awg_gs_health_state($uuid));
    }

    $registry = awg_gs_registry_read();
    foreach ($registry as $uuid => $record) {
        if (!isset($seen[$uuid])) {
            $rows[$uuid] = awg_gs_release_record((string)$uuid, $record);
        }
    }
    awg_gs_output(['result' => 'ok', 'rows' => $rows]);
}

$uuid = $arg1;
if (!awg_gs_valid_uuid($uuid)) {
    awg_gs_output(['result' => 'failed', 'message' => 'Invalid instance UUID'], 1);
}
$state = isset($argv[2]) ? strtolower(trim((string)$argv[2])) : 'unknown';
awg_gs_output(awg_gs_sync_one($uuid, $state));
