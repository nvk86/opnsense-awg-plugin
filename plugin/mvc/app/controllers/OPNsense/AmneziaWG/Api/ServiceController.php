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
            return ['state'=>'disabled','label'=>'health: disabled','failures'=>0,'monitored'=>0,'clients'=>[]];
        }
        if ($hasOffline) {
            $label = $maxFailures > 0 && $maxFailures < 3
                ? 'health: offline ' . $maxFailures . '/3'
                : 'health: offline';
            return ['state'=>'offline','label'=>$label,'failures'=>$maxFailures,'monitored'=>$monitored,'clients'=>$clients];
        }
        if ($hasStale) {
            return ['state'=>'stale','label'=>'health: stale','failures'=>0,'monitored'=>$monitored,'clients'=>$clients];
        }
        if ($hasWaiting) {
            return ['state'=>'waiting','label'=>'health: waiting','failures'=>0,'monitored'=>$monitored,'clients'=>$clients];
        }
        if ($hasStopped) {
            return ['state'=>'stopped','label'=>'health: stopped','failures'=>0,'monitored'=>$monitored,'clients'=>$clients];
        }
        return ['state'=>'online','label'=>'health: online','failures'=>0,'monitored'=>$monitored,'clients'=>$clients];
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
            return $decoded;
        }
        return ['status' => 'error', 'message' => $result, 'health' => $this->healthSummary()];
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
