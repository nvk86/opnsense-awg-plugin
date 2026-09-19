<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    // SEC-1: private keys are stored in protected files, not in config.xml.
    // The sentinel '::file::' in config.xml signals that the real key is on disk.
    // Multi-instance: one file per instance, keyed by the ArrayField uuid.
    const PRIVKEY_DIR      = '/usr/local/etc/amnezia';
    const PRIVKEY_SENTINEL = '::file::';

    // Handshake older than this is reported as 'no_handshake' (matches
    const HANDSHAKE_FRESH_SEC = 180;

    /**
     * Search/list instances for the bootgrid.
     * Rows are enriched with a 'runtime' field :
     *   running      — interface up, fresh handshake
     *   no_handshake — interface up, but no/stale handshake
     *   stopped      — interface does not exist
     * Enrichment is skipped (runtime='') when configd is unreachable.
     */
    public function searchItemAction()
    {
        $result = $this->searchBase(
            'instance',
            ['enabled', 'name', 'description', 'interface_number', 'peer_endpoint']
        );

        $status = json_decode((string)(new Backend())->configdRun('amneziawg status'), true);
        $live = null;
        if (is_array($status)) {
            $live = [];
            foreach (($status['tunnels'] ?? []) as $tunnel) {
                $live[(string)($tunnel['interface'] ?? '')] = (int)($tunnel['latest_handshake'] ?? 0);
            }
        }
        foreach ($result['rows'] as &$row) {
            if ($live === null) {
                $row['runtime'] = '';
                continue;
            }
            $iface = 'awg' . (int)($row['interface_number'] ?? 0);
            if (!array_key_exists($iface, $live)) {
                $row['runtime'] = 'stopped';
            } elseif ($live[$iface] > 0 && (time() - $live[$iface]) <= self::HANDSHAKE_FRESH_SEC) {
                $row['runtime'] = 'running';
            } else {
                $row['runtime'] = 'no_handshake';
            }

        }
        unset($row);
        return $result;
    }

    /**
     * Retrieve one instance (or defaults for the add dialog).
     * Masks private_key with a bullet placeholder and double-decodes I1-I5.
     */
    public function getItemAction($uuid = null)
    {
        $result = $this->getBase('instance', 'instance', $uuid);
        if (isset($result['instance'])) {
            $stored    = (string)($result['instance']['private_key'] ?? '');
            $keyOnDisk = $uuid !== null && file_exists($this->keyFilePath((string)$uuid));
            // Show a placeholder only when a usable key really exists.
            // A sentinel with a missing key file must surface as blank so the
            // user can repair it instead of seeing a false "key present" state.
            $hasUsableKey = $keyOnDisk || ($stored !== '' && $stored !== self::PRIVKEY_SENTINEL);
            $result['instance']['private_key'] = $hasUsableKey
                ? str_repeat(chr(0xE2) . chr(0x80) . chr(0xA2), 44)
                : '';
            // I1-I5 CPS tags contain angle brackets that get double-encoded
            // by the model layer — decode twice to restore raw tag syntax.
            foreach (['i1', 'i2', 'i3', 'i4', 'i5'] as $iField) {
                if (!empty($result['instance'][$iField])) {
                    $val = (string)$result['instance'][$iField];
                    $val = html_entity_decode($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $result['instance'][$iField] = html_entity_decode($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            // Suggest the lowest free interface number for new instances
            if ($uuid === null) {
                $result['instance']['interface_number'] = (string)$this->nextFreeInterfaceNumber();
            }
        }
        return $result;
    }

    /**
     * Add a new instance. The uuid is only known after addBase() creates the
     * node, so a submitted real private key is stashed and written afterwards.
     */
    public function addItemAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $body = $this->request->getPost('instance', null, []);

        $keyPrep = $this->prepareSubmittedKey($body, null);
        if (isset($keyPrep['validations'])) {
            return ['result' => 'failed', 'validations' => $keyPrep['validations']];
        }

        $validations = array_merge(
            $this->validateHFields($body, 'instance'),
            $this->validateAwg31Ranges($body, 'instance'),
            $this->validateKeepaliveRange($body['peer_persistent_keepalive'] ?? '', 'instance.peer_persistent_keepalive'),
            $this->validateInterfaceNumber($body, null),
            $this->validateListenPort($body, null),
            $this->validateHealthSettings($body)
        );
        if (!empty($validations)) {
            return ['result' => 'failed', 'validations' => $validations];
        }

        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;
        $result = $this->addBase('instance', 'instance');

        if (($result['result'] ?? '') !== 'failed' && $keyPrep['key'] !== null) {
            $uuid = (string)($result['uuid'] ?? '');
            if ($uuid !== '') {
                try {
                    $this->writePrivateKey($uuid, $keyPrep['key']);
                } catch (\RuntimeException $e) {
                    // addBase already persisted the node; remove it again so a
                    // failed key write never leaves a broken sentinel-only item.
                    $this->delBase('instance', $uuid);
                    return ['result' => 'failed', 'validations' => [
                        'instance.private_key' => $e->getMessage()
                    ]];
                }
            }
        }
        return $result;
    }

    /**
     * Update an existing instance.
     */
    public function setItemAction($uuid)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $body = $this->request->getPost('instance', null, []);

        $keyPrep = $this->prepareSubmittedKey($body, (string)$uuid);
        if (isset($keyPrep['validations'])) {
            return ['result' => 'failed', 'validations' => $keyPrep['validations']];
        }

        $validations = array_merge(
            $this->validateHFields($body, 'instance'),
            $this->validateAwg31Ranges($body, 'instance'),
            $this->validateKeepaliveRange($body['peer_persistent_keepalive'] ?? '', 'instance.peer_persistent_keepalive'),
            $this->validateInterfaceNumber($body, (string)$uuid),
            $this->validateListenPort($body, (string)$uuid),
            $this->validateHealthSettings($body)
        );
        if (!empty($validations)) {
            return ['result' => 'failed', 'validations' => $validations];
        }

        // Always store the sentinel in config.xml, never the raw key.
        // Persist the model first: if another field fails framework validation,
        // the existing on-disk key must remain untouched.
        $stagedKey = null;
        if ($keyPrep['key'] !== null) {
            try {
                $stagedKey = $this->stagePrivateKey($keyPrep['key']);
            } catch (\RuntimeException $e) {
                return ['result' => 'failed', 'validations' => [
                    'instance.private_key' => $e->getMessage()
                ]];
            }
        }

        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;
        $result = $this->setBase('instance', 'instance', $uuid);
        if (($result['result'] ?? '') === 'saved' && $stagedKey !== null) {
            try {
                $this->commitStagedPrivateKey($stagedKey, (string)$uuid);
                $stagedKey = null;
            } catch (\RuntimeException $e) {
                @unlink($stagedKey);
                return ['result' => 'failed', 'validations' => [
                    'instance.private_key' => $e->getMessage()
                ]];
            }
        } elseif ($stagedKey !== null) {
            @unlink($stagedKey);
        }
        return $result;
    }

    /**
     * Delete an instance and its protected key file.
     */
    public function delItemAction($uuid)
    {
        $result = $this->delBase('instance', $uuid);
        if (($result['result'] ?? '') === 'deleted') {
            // Release Force Down ownership immediately so a deleted client
            // never leaves a native gateway under plugin control.
            try {
                (new Backend())->configdRun('amneziawg gateway_sync_release ' . (string)$uuid);
            } catch (\Throwable $e) {
                // The periodic reconciler is the fallback if configd is unavailable.
            }
            // SEC-1: remove the orphaned key file together with the instance
            @unlink($this->keyFilePath((string)$uuid));
            @unlink('/var/run/amneziawg-health-' . preg_replace('/[^a-fA-F0-9\-]/', '', (string)$uuid) . '.json');
        }
        return $result;
    }

    /**
     * Toggle enabled state from the grid.
     */
    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    /**
     * Generate a new keypair via configd.
     * Stateless by design for the dialog flow: both keys are returned to the
     * browser so the dialog can populate its fields; persistence happens on
     * dialog Save through add/setItemAction. This re-scopes SEC-2 (the private
     * key transits to the client once, same as when a user pastes their own
     * key into the form) — it is never logged or stored outside the dialog.
     */
    public function genKeyPairAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg gen_keypair');
        $decoded = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['private_key']) || !isset($decoded['public_key'])) {
            $errMsg = $decoded['message'] ?? 'Failed to generate key pair';
            return ['status' => 'error', 'message' => $errMsg];
        }

        // HIGH-1: validate key format before trusting shell output
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $decoded['private_key'])
            || !preg_match('/^[A-Za-z0-9+\/]{43}=$/', $decoded['public_key'])) {
            return ['status' => 'error', 'message' => 'Generated keys have invalid Base64 format'];
        }

        return [
            'status'      => 'ok',
            'public_key'  => $decoded['public_key'],
            'private_key' => $decoded['private_key'],
        ];
    }

    /**
     * Classify the submitted private_key value.
     * Returns ['key' => string|null] where null means "keep existing key",
     * or ['validations' => [...]] on error.
     */
    private function prepareSubmittedKey(array $body, ?string $uuid): array
    {
        $submitted = trim($body['private_key'] ?? '');
        // Bullet placeholder (UTF-8 bullet 0xE2 0x80 0xA2) = do not change key
        $isBullet = strpos($submitted, "\xE2\x80\xA2") !== false;

        if ($isBullet) {
            // Upgrade safety: older installations may still have a valid
            // plaintext key in config.xml and no per-UUID key file yet.
            // Preserve/migrate it instead of replacing it with ::file:: and
            // silently losing the key on the first edit.
            if ($uuid !== null && !file_exists($this->keyFilePath($uuid))) {
                $node = $this->getModel()->getNodeByReference('instance.' . $uuid);
                $legacy = $node !== null ? trim((string)$node->private_key) : '';
                if ($legacy !== '' && $legacy !== self::PRIVKEY_SENTINEL &&
                    preg_match('/^[A-Za-z0-9+\/]{43}=$/', $legacy)) {
                    return ['key' => $legacy];
                }
            }
            return ['key' => null];
        }
        if ($submitted === '') {
            // Blank is acceptable only when a key file already exists for this instance
            if ($uuid === null || !file_exists($this->keyFilePath($uuid))) {
                return ['validations' => [
                    'instance.private_key' => 'A private key is required. Use Generate Keypair or paste your key.'
                ]];
            }
            return ['key' => null];
        }
        // IMP-3: validate that it's a proper WireGuard Base64 key
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $submitted)) {
            return ['validations' => [
                'instance.private_key' => 'Private key must be a valid Base64 WireGuard key (44 characters ending with =)'
            ]];
        }
        return ['key' => $submitted];
    }

    /**
     * Validate the optional active health monitor and native gateway sync.
     */
    private function validateHealthSettings(array $body): array
    {
        $errors = [];
        $target = trim((string)($body['health_target'] ?? ''));
        if ($target !== '' && filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors['instance.health_target'] = 'Health Probe Target must be an IPv4 address';
        }
        $monitor = (string)($body['health_monitor'] ?? '0') === '1';
        $sync = (string)($body['gateway_health_sync'] ?? '0') === '1';
        if ($sync && !$monitor) {
            $errors['instance.gateway_health_sync'] = 'Enable Health Monitor before Gateway Health Sync';
        }
        return $errors;
    }

    /**
     * Validate H1-H4: format, value range and mutual non-overlap within
     * this instance (awg driver requirement). Returns validation map.
     */
    private function validateHFields(array $body, string $prefix): array
    {
        $hRanges = []; // array of [low, high] for overlap check
        $validationErrors = [];
        foreach (['h1', 'h2', 'h3', 'h4'] as $hf) {
            $val = trim($body[$hf] ?? '');
            if ($val === '') {
                continue;
            }
            if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $val)) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' must be a number or range (e.g. 12345 or 12345-67890)';
                continue;
            }
            $parts = explode('-', $val, 2);
            $low  = (float)$parts[0];
            $high = isset($parts[1]) ? (float)$parts[1] : $low;
            if ($low < 1 || $high < 1 || $low > 4294967295 || $high > 4294967295) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' values must be in range 1-4294967295';
            } elseif ($high < $low) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' range start must not exceed range end';
            } else {
                foreach ($hRanges as $prev => [$pLow, $pHigh]) {
                    if ($low <= $pHigh && $pLow <= $high) {
                        $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' range must not overlap with ' . strtoupper($prev);
                        break;
                    }
                }
                if (!isset($validationErrors["{$prefix}.{$hf}"])) {
                    $hRanges[$hf] = [$low, $high];
                }
            }
        }
        return $validationErrors;
    }

    private function validateAwg31Ranges(array $body, string $prefix): array
    {
        $errors = [];
        foreach (['content_padding_addition','rekey_after_time','rekey_timeout','reject_after_time','keepalive_timeout','max_handshake_attempts'] as $field) {
            $value = trim((string)($body[$field] ?? ''));
            if ($value === '') continue;
            $err = $this->validateUnsignedRangeValue($value, 4294967295);
            if ($err !== '') $errors[$prefix . '.' . $field] = $err;
        }
        return $errors;
    }

    private function validateKeepaliveRange($value, string $field): array
    {
        $value = trim((string)$value);
        if ($value === '') return [];
        $err = $this->validateUnsignedRangeValue($value, 65535);
        return $err === '' ? [] : [$field => $err];
    }

    private function validateUnsignedRangeValue(string $value, int $max): string
    {
        if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $value)) return 'Must be a non-negative number or range';
        $parts = explode('-', $value, 2);
        $lo = (float)$parts[0];
        $hi = isset($parts[1]) ? (float)$parts[1] : $lo;
        if ($lo > $max || $hi > $max) return 'Value exceeds ' . $max;
        if ($hi < $lo) return 'Range start must not exceed range end';
        return '';
    }

    /**
     * interface_number must be unique across instances (each maps to awg<N>).
     * $selfUuid excludes the instance being edited from the check.
     */
    private function validateInterfaceNumber(array $body, ?string $selfUuid): array
    {
        $submitted = trim($body['interface_number'] ?? '');
        if ($submitted === '') {
            return [];
        }
        foreach ($this->getModel()->instance->iterateItems() as $nodeUuid => $node) {
            if ($selfUuid !== null && (string)$nodeUuid === $selfUuid) {
                continue;
            }
            if ((string)$node->interface_number === $submitted) {
                return ['instance.interface_number' =>
                    'Interface number ' . $submitted . ' is already used by client tunnel "' . (string)$node->name . '"'];
            }
        }
        // Client and server interfaces share the same awgN namespace.
        $serverModel = new \OPNsense\AmneziaWG\Server();
        foreach ($serverModel->server->iterateItems() as $node) {
            if ((string)$node->interface_number === $submitted) {
                return ['instance.interface_number' =>
                    'Interface number ' . $submitted . ' is already used by server "' . (string)$node->name . '"'];
            }
        }
        return [];
    }

    /**
     * Explicit ListenPort values must be unique across all AWG interfaces.
     * WireGuard binds the UDP socket on all addresses, so a second interface
     * cannot safely reuse the same port.
     */
    private function validateListenPort(array $body, ?string $selfUuid): array
    {
        $port = trim($body['listen_port'] ?? '');
        if ($port === '') {
            return [];
        }
        foreach ($this->getModel()->instance->iterateItems() as $uuid => $node) {
            if ($selfUuid !== null && (string)$uuid === $selfUuid) {
                continue;
            }
            if (trim((string)$node->listen_port) === $port) {
                return ['instance.listen_port' =>
                    'Listen port ' . $port . ' is already used by client tunnel "' . (string)$node->name . '"'];
            }
        }
        foreach ((new \OPNsense\AmneziaWG\Server())->server->iterateItems() as $node) {
            if (trim((string)$node->listen_port) === $port) {
                return ['instance.listen_port' =>
                    'Listen port ' . $port . ' is already used by server "' . (string)$node->name . '"'];
            }
        }
        return [];
    }

    /**
     * Lowest free interface number 0-99 for new instances.
     */
    private function nextFreeInterfaceNumber(): int
    {
        $used = [];
        foreach ($this->getModel()->instance->iterateItems() as $node) {
            $value = trim((string)$node->interface_number);
            if ($value !== '') {
                $used[(int)$value] = true;
            }
        }
        foreach ((new \OPNsense\AmneziaWG\Server())->server->iterateItems() as $node) {
            $value = trim((string)$node->interface_number);
            if ($value !== '') {
                $used[(int)$value] = true;
            }
        }
        for ($n = 0; $n <= 99; $n++) {
            if (!isset($used[$n])) {
                return $n;
            }
        }
        return 99;
    }

    /**
     * Protected key file path for an instance uuid.
     */
    private function keyFilePath(string $uuid): string
    {
        // uuid comes from the ArrayField/route — keep a strict charset anyway
        $safe = preg_replace('/[^a-fA-F0-9\-]/', '', $uuid);
        return self::PRIVKEY_DIR . '/' . $safe . '.key';
    }

    /**
     * Write the private key to the protected per-instance file with mode 0600.
     * @throws \RuntimeException if write fails
     */
    private function stagePrivateKey(string $key): string
    {
        if (!is_dir(self::PRIVKEY_DIR) && !mkdir(self::PRIVKEY_DIR, 0700, true)) {
            throw new \RuntimeException('Cannot create private key directory');
        }
        @chmod(self::PRIVKEY_DIR, 0700);
        $tmp = tempnam(self::PRIVKEY_DIR, '.keytmp-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temporary private key file');
        }
        if (file_put_contents($tmp, trim($key) . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write private key');
        }
        if (!chmod($tmp, 0600)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot secure private key permissions');
        }
        return $tmp;
    }

    private function commitStagedPrivateKey(string $tmp, string $uuid): void
    {
        if (!rename($tmp, $this->keyFilePath($uuid))) {
            throw new \RuntimeException('Cannot install private key');
        }
    }

    private function writePrivateKey(string $uuid, string $key): void
    {
        $tmp = $this->stagePrivateKey($key);
        try {
            $this->commitStagedPrivateKey($tmp, $uuid);
            $tmp = '';
        } finally {
            if ($tmp !== '' && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
