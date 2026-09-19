<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\AmneziaWG\General';
    protected static $internalServiceTemplate = 'OPNsense/AmneziaWG';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'amneziawg';

    private function runAction(string $command): array
    {
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun($command));
        $failed  = empty($output)
                || stripos($output, 'ERROR') !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $general = new \OPNsense\AmneziaWG\General();
        if ((string)$general->enabled !== '1') {
            // Apply while disabled must converge runtime to the disabled state,
            // not merely change config.xml while leaving live tunnels up.
            $stop = $this->runAction('amneziawg stop');
            $success = ($stop['result'] === 'ok') && strpos($stop['message'], 'OK') !== false;
            return [
                'result' => $success ? 'ok' : 'failed',
                'status' => $success ? 'disabled' : 'failed',
                'output' => $success
                    ? 'Service is disabled and all plugin-owned tunnels are stopped'
                    : $stop['message'],
            ];
        }
        $res = $this->runAction('amneziawg reconfigure');
        $success = ($res['result'] === 'ok')
            && strpos($res['message'], 'OK') !== false;
        return [
            'result' => $success ? 'ok' : 'failed',
            'status' => $success ? 'ok' : 'failed',
            'output' => $res['message'],
        ];
    }

    /**
     * POST /api/amneziawg/service/start
     */
    public function startAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg start');
    }

    /**
     * POST /api/amneziawg/service/stop
     */
    public function stopAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg stop');
    }

    /**
     * POST /api/amneziawg/service/restart
     */
    public function restartAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg restart');
    }

    // statusAction() is inherited from ApiMutableServiceControllerBase.

    /**
     * Resolve an instance uuid to its awgN interface name.
     * Returns '' when the uuid is malformed or unknown.
     */
    private function instanceInterface(string $uuid): string
    {
        if (!preg_match('/^[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/', $uuid)) {
            return '';
        }
        $model = new \OPNsense\AmneziaWG\Instance();
        $node  = $model->getNodeByReference('instance.' . $uuid);
        if ($node === null) {
            $serverModel = new \OPNsense\AmneziaWG\Server();
            $node = $serverModel->getNodeByReference('server.' . $uuid);
        }
        if ($node === null) {
            return '';
        }
        $ifnum = trim((string)$node->interface_number);
        return 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
    }

    /**
     * POST /api/amneziawg/service/start_instance/<uuid>
     * Brings up a single tunnel (per-row grid action).
     */
    public function startInstanceAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface = $this->instanceInterface((string)$uuid);
        if ($iface === '') {
            return ['result' => 'failed', 'message' => 'Unknown tunnel instance'];
        }
        return $this->runAction('amneziawg start_instance ' . $iface);
    }

    /**
     * POST /api/amneziawg/service/stop_instance/<uuid>
     * Brings down a single tunnel (per-row grid action).
     */
    public function stopInstanceAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface = $this->instanceInterface((string)$uuid);
        if ($iface === '') {
            return ['result' => 'failed', 'message' => 'Unknown tunnel instance'];
        }
        return $this->runAction('amneziawg stop_instance ' . $iface);
    }

    /**
     * POST /api/amneziawg/service/restart_instance/<uuid>
     * Restarts one tunnel without touching other instances.
     */
    public function restartInstanceAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface = $this->instanceInterface((string)$uuid);
        if ($iface === '') {
            return ['result' => 'failed', 'message' => 'Unknown tunnel instance'];
        }
        return $this->runAction('amneziawg restart_instance ' . $iface);
    }

    /**
     * GET /api/amneziawg/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * Aggregate active client health for the General page.
     *
     * The badge intentionally shows the worst monitored client only; detailed
     * per-client health remains in Diagnostics.
     */
    private function healthSummary(): array
    {
        $config = \OPNsense\Core\Config::getInstance()->object();
        $clients = [];
        $maxFailures = 0;
        $hasOffline = false;
        $hasWaiting = false;
        $hasStale = false;
        $hasStopped = false;
        $monitored = 0;
        $online = 0;

        foreach (($config->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
            if ((string)($inst->enabled ?? '0') !== '1'
                || (string)($inst->health_monitor ?? '0') !== '1') {
                continue;
            }

            $uuid = (string)$inst['uuid'];
            $name = trim((string)($inst->name ?? ''));
            if ($name === '') {
                $name = 'awg' . (string)(int)($inst->interface_number ?? 0);
            }

            $monitored++;
            $path = '/var/run/amneziawg-health-' . preg_replace('/[^a-fA-F0-9\-]/', '', $uuid) . '.json';
            $health = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
            $state = 'waiting';
            $failures = 0;

            if (is_array($health) && (int)($health['checked_at'] ?? 0) > 0) {
                $age = time() - (int)$health['checked_at'];
                if (($health['status'] ?? '') === 'stopped') {
                    $state = 'stopped';
                    $hasStopped = true;
                } elseif (($health['status'] ?? '') === 'waiting') {
                    $state = 'waiting';
                    $hasWaiting = true;
                } elseif ($age > 150) {
                    $state = 'stale';
                    $hasStale = true;
                } elseif (!empty($health['online'])) {
                    $state = 'online';
                    $online++;
                } else {
                    $state = 'offline';
                    $failures = (int)($health['consecutive_failures'] ?? 0);
                    $maxFailures = max($maxFailures, $failures);
                    $hasOffline = true;
                }
            } else {
                $hasWaiting = true;
            }

            $clients[] = [
                'name' => $name,
                'state' => $state,
                'failures' => $failures,
            ];
        }

        if ($monitored === 0) {
            return ['state'=>'disabled','label'=>'Health: disabled','failures'=>0,'monitored'=>0,'online'=>0,'clients'=>[]];
        }
        if ($hasOffline) {
            $label = 'Health: ' . $online . '/' . $monitored . ' online';
            return ['state'=>'offline','label'=>$label,'failures'=>$maxFailures,'monitored'=>$monitored,'online'=>$online,'clients'=>$clients];
        }
        if ($hasStale) {
            return ['state'=>'stale','label'=>'Health: ' . $online . '/' . $monitored . ' online','failures'=>0,'monitored'=>$monitored,'online'=>$online,'clients'=>$clients];
        }
        if ($hasWaiting) {
            return ['state'=>'waiting','label'=>'Health: ' . $online . '/' . $monitored . ' online','failures'=>0,'monitored'=>$monitored,'online'=>$online,'clients'=>$clients];
        }
        if ($hasStopped) {
            return ['state'=>'stopped','label'=>'Health: ' . $online . '/' . $monitored . ' online','failures'=>0,'monitored'=>$monitored,'online'=>$online,'clients'=>$clients];
        }
        return ['state'=>'online','label'=>'Health: ' . $online . '/' . $monitored . ' online','failures'=>0,'monitored'=>$monitored,'online'=>$online,'clients'=>$clients];
    }

    /**
     * Aggregate enabled client/server runtime counts for the General page.
     */
    private function runtimeSummary(array $status): array
    {
        $live = [];
        foreach (($status['tunnels'] ?? []) as $tunnel) {
            $iface = trim((string)($tunnel['interface'] ?? ''));
            if ($iface !== '' && !empty($tunnel['up'])) {
                $live[$iface] = true;
            }
        }

        $summary = [
            'clients' => ['running' => 0, 'total' => 0],
            'servers' => ['running' => 0, 'total' => 0],
        ];
        $config = \OPNsense\Core\Config::getInstance()->object();

        foreach (($config->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
            if ((string)($inst->enabled ?? '0') !== '1') {
                continue;
            }
            $summary['clients']['total']++;
            $iface = 'awg' . (string)(int)($inst->interface_number ?? 0);
            if (isset($live[$iface])) {
                $summary['clients']['running']++;
            }
        }

        foreach (($config->OPNsense->amneziawg->servers->server ?? []) as $server) {
            if ((string)($server->enabled ?? '0') !== '1') {
                continue;
            }
            $summary['servers']['total']++;
            $iface = 'awg' . (string)(int)($server->interface_number ?? 0);
            if (isset($live[$iface])) {
                $summary['servers']['running']++;
            }
        }

        return $summary;
    }

    /**
     * GET /api/amneziawg/service/tunnel_status
     */
    public function tunnelStatusAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg status');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $decoded['health'] = $this->healthSummary();
            $decoded['summary'] = $this->runtimeSummary($decoded);
            return $decoded;
        }
        return [
            'status' => 'error',
            'message' => $result,
            'health' => $this->healthSummary(),
            'summary' => ['clients'=>['running'=>0,'total'=>0], 'servers'=>['running'=>0,'total'=>0]],
        ];
    }

    /**
     * Sanitize an optional interface token from the request (anti-injection).
     * Returns 'awgN' or empty string.
     */
    private function requestedInterface(): string
    {
        $iface = (string)$this->request->get('interface', null, '');
        if ($iface === '') {
            $iface = (string)$this->request->getPost('interface', null, '');
        }
        return preg_match('/^awg\d{1,2}$/', $iface) ? $iface : '';
    }

    /**
     * GET /api/amneziawg/service/diagnostics[?interface=awgN]
     * Returns interface stats as JSON. Without a parameter the first
     * enabled instance is reported (multi-instance default).
     */
    public function diagnosticsAction()
    {
        $iface   = $this->requestedInterface();
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun(trim('amneziawg ifstats ' . $iface)));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats'];
        }
        return $data;
    }


    /**
     * POST /api/amneziawg/service/health/<uuid>
     * Runs one active client data-plane probe immediately.
     */
    public function healthAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid = (string)$uuid;
        if (!preg_match('/^[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/', $uuid)) {
            return ['result' => 'failed', 'message' => 'Invalid client UUID'];
        }
        $model = new \OPNsense\AmneziaWG\Instance();
        if ($model->getNodeByReference('instance.' . $uuid) === null) {
            return ['result' => 'failed', 'message' => 'Unknown client instance'];
        }

        $backend = new Backend();
        $output = trim((string)$backend->configdRun('amneziawg health ' . $uuid));
        if ($output === '') {
            return ['result' => 'failed', 'message' => 'No response from health probe'];
        }
        $decoded = json_decode($output, true);
        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : ['result' => 'failed', 'message' => $output];
    }

    /**
     * Escape a Prometheus label value without exposing any configuration
     * material other than the explicitly selected labels.
     */
    private function prometheusEscapeLabel(string $value): string
    {
        return str_replace(
            ["\\", "\n", "\""],
            ["\\\\", "\\n", "\\\""],
            $value
        );
    }

    private function prometheusLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . $this->prometheusEscapeLabel((string)$value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    private function prometheusSample(string $name, $value, array $labels = []): string
    {
        if (is_float($value)) {
            $number = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
            if ($number === '') {
                $number = '0';
            }
        } else {
            $number = (string)(int)$value;
        }
        return $name . $this->prometheusLabels($labels) . ' ' . $number;
    }

    private function prometheusHealthState(
        array $health,
        bool $probeEnabled,
        bool $runtimeEnabled,
        bool $manualStopped
    ): string {
        if (!$probeEnabled) {
            return 'disabled';
        }
        if (!$runtimeEnabled || $manualStopped || (string)($health['status'] ?? '') === 'stopped') {
            return 'stopped';
        }

        $checkedAt = (int)($health['checked_at'] ?? 0);
        if ($checkedAt <= 0 || (string)($health['status'] ?? '') === 'waiting') {
            return 'waiting';
        }
        if ((time() - $checkedAt) > 150) {
            return 'stale';
        }
        return !empty($health['online']) ? 'online' : 'offline';
    }

    /**
     * GET /api/amneziawg/service/metrics
     *
     * Prometheus text exposition built only from current runtime status and
     * the existing one-minute health cache. Scraping this endpoint never runs
     * an active health probe and never changes tunnel or gateway state.
     */
    public function metricsAction()
    {
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');
            $this->response->setHeader('Allow', 'GET');
            return "GET required\n";
        }

        $this->response->setHeader(
            'Content-Type',
            'text/plain; version=0.0.4; charset=utf-8'
        );
        $this->response->setHeader('Cache-Control', 'no-store');

        $config = \OPNsense\Core\Config::getInstance()->object();
        $general = $config->OPNsense->amneziawg->general ?? null;
        $serviceEnabled = (string)($general->enabled ?? '0') === '1';
        $watchdogEnabled = (string)($general->watchdog ?? '0') === '1';
        $serviceStopped = is_file('/var/run/amneziawg_stopped.flag');

        $runtimeRaw = trim((string)(new Backend())->configdRun('amneziawg status'));
        $runtime = json_decode($runtimeRaw, true);
        $runtimeByInterface = [];
        if (is_array($runtime)) {
            foreach (($runtime['tunnels'] ?? []) as $tunnel) {
                if (!is_array($tunnel)) {
                    continue;
                }
                $iface = trim((string)($tunnel['interface'] ?? ''));
                if ($iface !== '') {
                    $runtimeByInterface[$iface] = $tunnel;
                }
            }
        }

        $pluginVersion = trim((string)@file_get_contents(
            '/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt'
        ));
        if ($pluginVersion === '') {
            $pluginVersion = 'unknown';
        }

        $lines = [
            '# HELP opnsense_awg_plugin_info Plugin build information.',
            '# TYPE opnsense_awg_plugin_info gauge',
            $this->prometheusSample(
                'opnsense_awg_plugin_info',
                1,
                ['version' => $pluginVersion]
            ),
            '# HELP opnsense_awg_service_enabled Whether AmneziaWG is enabled in configuration.',
            '# TYPE opnsense_awg_service_enabled gauge',
            $this->prometheusSample('opnsense_awg_service_enabled', $serviceEnabled ? 1 : 0),
            '# HELP opnsense_awg_service_manually_stopped Whether the whole service was manually stopped.',
            '# TYPE opnsense_awg_service_manually_stopped gauge',
            $this->prometheusSample('opnsense_awg_service_manually_stopped', $serviceStopped ? 1 : 0),
            '# HELP opnsense_awg_watchdog_enabled Whether automatic runtime recovery is enabled.',
            '# TYPE opnsense_awg_watchdog_enabled gauge',
            $this->prometheusSample('opnsense_awg_watchdog_enabled', $watchdogEnabled ? 1 : 0),
            '# HELP opnsense_awg_client_enabled Whether the client is enabled in configuration.',
            '# TYPE opnsense_awg_client_enabled gauge',
            '# HELP opnsense_awg_client_up Whether the client AWG interface is currently up.',
            '# TYPE opnsense_awg_client_up gauge',
            '# HELP opnsense_awg_client_manual_stopped Whether the client was manually stopped.',
            '# TYPE opnsense_awg_client_manual_stopped gauge',
            '# HELP opnsense_awg_health_probe_enabled Whether scheduled active health probing is enabled for the client.',
            '# TYPE opnsense_awg_health_probe_enabled gauge',
            '# HELP opnsense_awg_gateway_health_sync_enabled Whether native gateway health synchronization is enabled.',
            '# TYPE opnsense_awg_gateway_health_sync_enabled gauge',
            '# HELP opnsense_awg_health_online Whether the cached client health is fresh and online.',
            '# TYPE opnsense_awg_health_online gauge',
            '# HELP opnsense_awg_health_state Current cached health state represented by a single labeled sample.',
            '# TYPE opnsense_awg_health_state gauge',
            '# HELP opnsense_awg_health_latency_seconds Last successful or failed probe latency in seconds.',
            '# TYPE opnsense_awg_health_latency_seconds gauge',
            '# HELP opnsense_awg_health_consecutive_failures Consecutive active health probe failures.',
            '# TYPE opnsense_awg_health_consecutive_failures gauge',
            '# HELP opnsense_awg_health_last_check_timestamp_seconds Unix timestamp of the last health check.',
            '# TYPE opnsense_awg_health_last_check_timestamp_seconds gauge',
            '# HELP opnsense_awg_health_last_ok_timestamp_seconds Unix timestamp of the last successful health check.',
            '# TYPE opnsense_awg_health_last_ok_timestamp_seconds gauge',
            '# HELP opnsense_awg_health_last_failure_timestamp_seconds Unix timestamp of the last failed health check.',
            '# TYPE opnsense_awg_health_last_failure_timestamp_seconds gauge',
            '# HELP opnsense_awg_health_last_restart_timestamp_seconds Unix timestamp of the last watchdog restart.',
            '# TYPE opnsense_awg_health_last_restart_timestamp_seconds gauge',
            '# HELP opnsense_awg_health_age_seconds Age of the cached health result in seconds.',
            '# TYPE opnsense_awg_health_age_seconds gauge',
            '# HELP opnsense_awg_client_latest_handshake_timestamp_seconds Latest AWG peer handshake timestamp for the client tunnel.',
            '# TYPE opnsense_awg_client_latest_handshake_timestamp_seconds gauge',
            '# HELP opnsense_awg_server_enabled Whether the server is enabled in configuration.',
            '# TYPE opnsense_awg_server_enabled gauge',
            '# HELP opnsense_awg_server_up Whether the server AWG interface is currently up.',
            '# TYPE opnsense_awg_server_up gauge',
            '# HELP opnsense_awg_server_latest_handshake_timestamp_seconds Latest peer handshake timestamp seen on the server tunnel.',
            '# TYPE opnsense_awg_server_latest_handshake_timestamp_seconds gauge',
        ];

        foreach (($config->OPNsense->amneziawg->instances->instance ?? []) as $inst) {
            $uuid = (string)$inst['uuid'];
            if (!preg_match('/^[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', $uuid)) {
                continue;
            }

            $ifnum = trim((string)($inst->interface_number ?? ''));
            $iface = 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
            $name = trim((string)($inst->name ?? ''));
            if ($name === '') {
                $name = $iface;
            }
            $labels = ['name' => $name, 'interface' => $iface];

            $enabled = (string)($inst->enabled ?? '0') === '1';
            $healthMonitor = (string)($inst->health_monitor ?? '0') === '1';
            $gatewaySync = (string)($inst->gateway_health_sync ?? '0') === '1';
            $probeEnabled = $healthMonitor || $gatewaySync;
            $manualStopped = is_file('/var/run/amneziawg_stopped_' . $iface . '.flag');
            $runtimeEnabled = $serviceEnabled && $enabled && !$serviceStopped;

            $tunnel = $runtimeByInterface[$iface] ?? [];
            $up = !empty($tunnel['up']);
            $latestHandshake = (int)($tunnel['latest_handshake'] ?? 0);

            $healthPath = '/var/run/amneziawg-health-' . $uuid . '.json';
            $health = is_file($healthPath)
                ? json_decode((string)@file_get_contents($healthPath), true)
                : [];
            if (!is_array($health)) {
                $health = [];
            }

            $state = $this->prometheusHealthState(
                $health,
                $probeEnabled,
                $runtimeEnabled,
                $manualStopped
            );
            $checkedAt = (int)($health['checked_at'] ?? 0);
            $healthAge = $checkedAt > 0 ? max(0, time() - $checkedAt) : 0;

            $lines[] = $this->prometheusSample('opnsense_awg_client_enabled', $enabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_client_up', $up ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_client_manual_stopped', $manualStopped ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_health_probe_enabled', $probeEnabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_gateway_health_sync_enabled', $gatewaySync ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_health_online', $state === 'online' ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_health_state', 1, $labels + ['state' => $state]);
            if (isset($health['latency_ms']) && is_numeric($health['latency_ms'])) {
                $lines[] = $this->prometheusSample(
                    'opnsense_awg_health_latency_seconds',
                    ((float)$health['latency_ms']) / 1000,
                    $labels
                );
            }
            $lines[] = $this->prometheusSample(
                'opnsense_awg_health_consecutive_failures',
                (int)($health['consecutive_failures'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample('opnsense_awg_health_last_check_timestamp_seconds', $checkedAt, $labels);
            $lines[] = $this->prometheusSample(
                'opnsense_awg_health_last_ok_timestamp_seconds',
                (int)($health['last_ok'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample(
                'opnsense_awg_health_last_failure_timestamp_seconds',
                (int)($health['last_failure'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample(
                'opnsense_awg_health_last_restart_timestamp_seconds',
                (int)($health['last_restart'] ?? 0),
                $labels
            );
            $lines[] = $this->prometheusSample('opnsense_awg_health_age_seconds', $healthAge, $labels);
            $lines[] = $this->prometheusSample(
                'opnsense_awg_client_latest_handshake_timestamp_seconds',
                $latestHandshake,
                $labels
            );
        }

        foreach (($config->OPNsense->amneziawg->servers->server ?? []) as $server) {
            $ifnum = trim((string)($server->interface_number ?? ''));
            $iface = 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
            $name = trim((string)($server->name ?? ''));
            if ($name === '') {
                $name = $iface;
            }
            $labels = ['name' => $name, 'interface' => $iface];
            $enabled = (string)($server->enabled ?? '0') === '1';
            $tunnel = $runtimeByInterface[$iface] ?? [];

            $lines[] = $this->prometheusSample('opnsense_awg_server_enabled', $enabled ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample('opnsense_awg_server_up', !empty($tunnel['up']) ? 1 : 0, $labels);
            $lines[] = $this->prometheusSample(
                'opnsense_awg_server_latest_handshake_timestamp_seconds',
                (int)($tunnel['latest_handshake'] ?? 0),
                $labels
            );
        }

        return implode("\n", $lines) . "\n";
    }


    /**
     * POST /api/amneziawg/service/log
     * Returns last 150 lines of amneziawg.log (POST-only: log may contain IPs)
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = (string)$backend->configdRun('amneziawg log');
        return ['log' => $output];
    }

    /**
     * POST /api/amneziawg/service/validate
     * Validates config without applying (dry-run)
     */
    public function validateAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $res = $this->runAction('amneziawg validate');
        return $res;
    }

}
