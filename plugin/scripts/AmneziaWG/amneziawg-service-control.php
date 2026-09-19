#!/usr/local/bin/php
<?php

// IMP-9: use absolute path instead of relying on PHP include_path
require_once('/usr/local/etc/inc/config.inc');

define('AWG_CONF_DIR', '/usr/local/etc/amnezia');
define('AWG_BIN',      '/usr/local/bin/awg');
define('AWG_QUICK',    '/usr/local/bin/awg-quick');
define('AWG_PID_FILE', '/var/run/amneziawg.pid');

// IMP-8: check that required binaries exist before any operation
function awg_check_binaries(): bool
{
    foreach ([AWG_BIN, AWG_QUICK] as $bin) {
        if (!file_exists($bin) || !is_executable($bin)) {
            awg_log('ERROR: required binary not found or not executable: ' . $bin);
            return false;
        }
    }
    return true;
}

// Check that if_awg kernel module is loaded; attempt kldload if not
function awg_check_kmod(): bool
{
    exec('/sbin/kldstat -q -m if_awg 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        awg_log('WARNING: if_awg kernel module not loaded, attempting kldload...');
        exec('/sbin/kldload if_awg 2>&1', $loadOut, $loadRc);
        if ($loadRc !== 0) {
            awg_log('ERROR: failed to load if_awg kernel module: ' . implode(' ', $loadOut));
            return false;
        }
        awg_log('if_awg kernel module loaded successfully');
    }
    return true;
}

define('AWG_PRIVKEY_SENTINEL', '::file::');
define('AWG_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt');
define('AWG_STOPPED_FLAG', '/var/run/amneziawg_stopped.flag');

function awg_service_enabled(): bool
{
    $config = OPNsense\Core\Config::getInstance()->object();
    return (string)($config->OPNsense->amneziawg->general->enabled ?? '0') === '1';
}

// Anti-injection guard for interface tokens passed via configd parameters
function awg_valid_iface(string $tok): bool
{
    return preg_match('/^awg\d{1,2}$/', $tok) === 1;
}

// Per-instance stopped flag: set by stop_instance, cleared by start_instance.
// Watchdog skips flagged interfaces so a per-row Stop in the grid sticks.
// $iface must pass awg_valid_iface() before calling.
function awg_instance_stopped_flag(string $iface): string
{
    return '/var/run/amneziawg_stopped_' . $iface . '.flag';
}

// Service-level actions (start/restart/reconfigure/stop) reset per-row stops:
// they either bring every enabled tunnel up or take the whole service down.
function awg_clear_instance_stopped_flags(): void
{
    foreach (glob('/var/run/amneziawg_stopped_awg*.flag') ?: [] as $flag) {
        @unlink($flag);
    }
}

function awg_health_cache_invalidate_iface(string $iface): void
{
    if (!preg_match('/^awg\d{1,2}$/D', $iface)) {
        return;
    }
    foreach (awg_get_instances() as $inst) {
        if (($inst['mode'] ?? 'client') === 'server' || ($inst['interface'] ?? '') !== $iface) {
            continue;
        }
        $uuid = (string)($inst['uuid'] ?? '');
        if (preg_match('/^[0-9a-fA-F-]{36}$/D', $uuid)) {
            @unlink('/var/run/amneziawg-health-' . $uuid . '.json');
        }
        return;
    }
}

function awg_health_cache_invalidate_clients(): void
{
    foreach (awg_get_instances() as $inst) {
        if (($inst['mode'] ?? 'client') === 'server') {
            continue;
        }
        $uuid = (string)($inst['uuid'] ?? '');
        if (preg_match('/^[0-9a-fA-F-]{36}$/D', $uuid)) {
            @unlink('/var/run/amneziawg-health-' . $uuid . '.json');
        }
    }
}

function awg_get_instances(): array
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $container = $config->OPNsense->amneziawg->instances ?? null;
    $result = [];
    if (isset($container) && isset($container->instance)) {
    foreach ($container->instance as $inst) {
        if ((string)($inst->enabled ?? '0') !== '1') {
            continue;
        }
        // R5: raw SimpleXML access — uuid is a node attribute set by ArrayField
        $uuid  = (string)($inst->attributes()['uuid'] ?? '');
        $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;

        // SEC-1: read private key from protected per-uuid file if sentinel is stored
        $privKeyRaw = (string)($inst->private_key ?? '');
        if ($privKeyRaw === AWG_PRIVKEY_SENTINEL) {
            $keyFile = AWG_CONF_DIR . '/' . $uuid . '.key';
            if ($uuid === '' || !file_exists($keyFile)) {
                awg_log('ERROR: private key file not found: ' . $keyFile . ' — awg' . $ifnum . ' will fail validation/start');
                $privKeyRaw = '';
            } else {
                $privKeyRaw = trim(file_get_contents($keyFile));
            }
        }

        $result[] = [
            'uuid'                      => $uuid,
            'interface'                 => 'awg' . $ifnum,
            'private_key'               => $privKeyRaw,
            'address'                   => (string)($inst->address                   ?? ''),
            'listen_port'               => (string)($inst->listen_port               ?? ''),
            'dns'                       => (string)($inst->dns                       ?? ''),
            'mtu'                       => (string)($inst->mtu                       ?? ''),
            'jc'                        => (string)($inst->jc                        ?? ''),
            'jmin'                      => (string)($inst->jmin                      ?? ''),
            'jmax'                      => (string)($inst->jmax                      ?? ''),
            's1'                        => (string)($inst->s1                        ?? ''),
            's2'                        => (string)($inst->s2                        ?? ''),
            's3'                        => (string)($inst->s3                        ?? ''),
            's4'                        => (string)($inst->s4                        ?? ''),
            'h1'                        => (string)($inst->h1                        ?? ''),
            'h2'                        => (string)($inst->h2                        ?? ''),
            'h3'                        => (string)($inst->h3                        ?? ''),
            'h4'                        => (string)($inst->h4                        ?? ''),
            'header_protection_key'     => (string)($inst->header_protection_key     ?? ''),
            'content_padding_addition'  => (string)($inst->content_padding_addition  ?? ''),
            'rekey_after_time'          => (string)($inst->rekey_after_time          ?? ''),
            'rekey_timeout'             => (string)($inst->rekey_timeout             ?? ''),
            'reject_after_time'         => (string)($inst->reject_after_time         ?? ''),
            'keepalive_timeout'         => (string)($inst->keepalive_timeout         ?? ''),
            'max_handshake_attempts'    => (string)($inst->max_handshake_attempts    ?? ''),
            'random_trailers'           => (string)($inst->random_trailers           ?? ''),
            'disable_cookies'           => (string)($inst->disable_cookies           ?? ''),
            // I1-I5 CPS tags contain angle brackets — double-decode HTML entities from config.xml
            'i1'                        => html_entity_decode(html_entity_decode((string)($inst->i1 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i2'                        => html_entity_decode(html_entity_decode((string)($inst->i2 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i3'                        => html_entity_decode(html_entity_decode((string)($inst->i3 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i4'                        => html_entity_decode(html_entity_decode((string)($inst->i4 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i5'                        => html_entity_decode(html_entity_decode((string)($inst->i5 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'peer_public_key'           => (string)($inst->peer_public_key           ?? ''),
            'peer_preshared_key'        => (string)($inst->peer_preshared_key        ?? ''),
            'peer_endpoint'             => (string)($inst->peer_endpoint             ?? ''),
            'peer_allowed_ips'          => (string)($inst->peer_allowed_ips          ?? ''),
            'peer_persistent_keepalive' => (string)($inst->peer_persistent_keepalive ?? ''),
        ];
    }
    }
    // Server instances share the same awgN interface namespace as clients.
    $servers = $config->OPNsense->amneziawg->servers ?? null;
    $peersContainer = $config->OPNsense->amneziawg->peers ?? null;
    if (isset($servers) && isset($servers->server)) {
        foreach ($servers->server as $srv) {
            if ((string)($srv->enabled ?? '0') !== '1') {
                continue;
            }
            $uuid = (string)($srv->attributes()['uuid'] ?? '');
            $ifnum = !empty((string)($srv->interface_number ?? '')) ? (int)(string)$srv->interface_number : 0;
            $privKeyRaw = (string)($srv->private_key ?? '');
            if ($privKeyRaw === AWG_PRIVKEY_SENTINEL) {
                $keyFile = AWG_CONF_DIR . '/server-' . $uuid . '.key';
                if ($uuid === '' || !file_exists($keyFile)) {
                    awg_log('ERROR: server private key file not found: ' . $keyFile . ' — awg' . $ifnum . ' will fail validation/start');
                    $privKeyRaw = '';
                } else {
                    $privKeyRaw = trim(file_get_contents($keyFile));
                }
            }
            $srvPeers = [];
            if (isset($peersContainer) && isset($peersContainer->peer)) {
                foreach ($peersContainer->peer as $peer) {
                    if ((string)($peer->enabled ?? '0') !== '1' || (string)($peer->server ?? '') !== $uuid) {
                        continue;
                    }
                    $srvPeers[] = [
                        'public_key' => (string)($peer->public_key ?? ''),
                        'preshared_key' => (string)($peer->preshared_key ?? ''),
                        'allowed_ips' => (string)($peer->allowed_ips ?? ''),
                        'persistent_keepalive' => (string)($peer->persistent_keepalive ?? ''),
                    ];
                }
            }
            $result[] = [
                'uuid' => $uuid,
                'mode' => 'server',
                'interface' => 'awg' . $ifnum,
                'private_key' => $privKeyRaw,
                'address' => (string)($srv->address ?? ''),
                'listen_port' => (string)($srv->listen_port ?? ''),
                'dns' => '',
                'mtu' => (string)($srv->mtu ?? ''),
                'jc' => (string)($srv->jc ?? ''), 'jmin' => (string)($srv->jmin ?? ''), 'jmax' => (string)($srv->jmax ?? ''),
                's1' => (string)($srv->s1 ?? ''), 's2' => (string)($srv->s2 ?? ''), 's3' => (string)($srv->s3 ?? ''), 's4' => (string)($srv->s4 ?? ''),
                'h1' => (string)($srv->h1 ?? ''), 'h2' => (string)($srv->h2 ?? ''), 'h3' => (string)($srv->h3 ?? ''), 'h4' => (string)($srv->h4 ?? ''),
                'header_protection_key' => (string)($srv->header_protection_key ?? ''),
                'content_padding_addition' => (string)($srv->content_padding_addition ?? ''),
                'rekey_after_time' => (string)($srv->rekey_after_time ?? ''),
                'rekey_timeout' => (string)($srv->rekey_timeout ?? ''),
                'reject_after_time' => (string)($srv->reject_after_time ?? ''),
                'keepalive_timeout' => (string)($srv->keepalive_timeout ?? ''),
                'max_handshake_attempts' => (string)($srv->max_handshake_attempts ?? ''),
                'random_trailers' => (string)($srv->random_trailers ?? ''),
                'disable_cookies' => (string)($srv->disable_cookies ?? ''),
                'i1' => html_entity_decode(html_entity_decode((string)($srv->i1 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'i2' => html_entity_decode(html_entity_decode((string)($srv->i2 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'i3' => html_entity_decode(html_entity_decode((string)($srv->i3 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'i4' => html_entity_decode(html_entity_decode((string)($srv->i4 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'i5' => html_entity_decode(html_entity_decode((string)($srv->i5 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'peers' => $srvPeers,
            ];
        }
    }
    return $result;
}

function awg_sanitize(string $value): string
{
    return str_replace(["\n", "\r"], '', $value);
}

function awg_atomic_write_bytes(string $path, string $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        awg_log('ERROR: failed to create directory for atomic write: ' . $dir);
        return false;
    }

    $tmpPath = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(6));

    if (file_put_contents($tmpPath, $data, LOCK_EX) === false) {
        awg_log('ERROR: failed to write temporary file: ' . $tmpPath);
        @unlink($tmpPath);
        return false;
    }

    if (!chmod($tmpPath, 0600)) {
        awg_log('ERROR: failed to secure temporary file: ' . $tmpPath);
        @unlink($tmpPath);
        return false;
    }

    if (!rename($tmpPath, $path)) {
        awg_log('ERROR: failed to atomically install file: ' . $path);
        @unlink($tmpPath);
        return false;
    }

    return true;
}

function awg_write_conf(array $inst, ?string $pathOverride = null): string
{
    $lines = ['[Interface]'];
    $lines[] = 'PrivateKey = ' . awg_sanitize($inst['private_key']);
    $lines[] = 'Address = '    . awg_sanitize($inst['address']);
    $lines[] = 'Table = off';

    if (!empty($inst['listen_port'])) {
        $lines[] = 'ListenPort = ' . awg_sanitize($inst['listen_port']);
    }
    if (!empty($inst['dns'])) {
        $lines[] = 'DNS = ' . awg_sanitize($inst['dns']);
    }
    if (!empty($inst['mtu'])) {
        $lines[] = 'MTU = ' . awg_sanitize($inst['mtu']);
    }
    // Obfuscation parameters
    $obf = ['jc'=>'Jc','jmin'=>'Jmin','jmax'=>'Jmax','s1'=>'S1','s2'=>'S2','s3'=>'S3','s4'=>'S4',
            'h1'=>'H1','h2'=>'H2','h3'=>'H3','h4'=>'H4',
            'header_protection_key'=>'HeaderProtectionKey',
            'content_padding_addition'=>'ContentPaddingAddition',
            'rekey_after_time'=>'RekeyAfterTime','rekey_timeout'=>'RekeyTimeout',
            'reject_after_time'=>'RejectAfterTime','keepalive_timeout'=>'KeepaliveTimeout',
            'max_handshake_attempts'=>'MaxHandshakeAttempts',
            'i1'=>'I1','i2'=>'I2','i3'=>'I3','i4'=>'I4','i5'=>'I5'];
    // Validate H1-H4: non-zero uint32 values/ranges; ranges must not overlap
    // Supports single values (e.g. "12345") and ranges (e.g. "12345-67890")
    $hRanges = [];
    foreach (['h1','h2','h3','h4'] as $hk) {
        $raw = trim($inst[$hk] ?? '');
        if ($raw === '') {
            continue;
        }
        if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $raw)) {
            awg_log("WARNING: {$hk}={$raw} has invalid format. Skipping {$hk}.");
            $inst[$hk] = '';
            continue;
        }
        $parts = explode('-', $raw, 2);
        $low  = (float)$parts[0];
        $high = isset($parts[1]) ? (float)$parts[1] : $low;
        if ($low < 1 || $high < 1 || $low > 4294967295 || $high > 4294967295 || $high < $low) {
            awg_log("WARNING: {$hk}={$raw} is invalid (values must be 1-4294967295, start <= end). Skipping {$hk}.");
            $inst[$hk] = '';
            continue;
        }
        // Check overlap with previously validated H ranges
        $overlap = false;
        foreach ($hRanges as $prev => [$pLow, $pHigh]) {
            if ($low <= $pHigh && $pLow <= $high) {
                awg_log("WARNING: {$hk}={$raw} overlaps with {$prev}. Skipping {$hk}.");
                $inst[$hk] = '';
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $hRanges[$hk] = [$low, $high];
        }
    }
    foreach ($obf as $k => $label) {
        if (array_key_exists($k, $inst) && (string)$inst[$k] !== '') {
            $lines[] = "$label = " . awg_sanitize((string)$inst[$k]);
        }
    }
    foreach (['random_trailers' => 'RandomTrailers', 'disable_cookies' => 'DisableCookies'] as $k => $label) {
        if (array_key_exists($k, $inst) && $inst[$k] !== '') {
            $lines[] = $label . ' = ' . ((string)$inst[$k] === '1' || strtolower((string)$inst[$k]) === 'on' ? 'on' : 'off');
        }
    }

    if (($inst['mode'] ?? 'client') === 'server') {
        foreach (($inst['peers'] ?? []) as $peer) {
            if (empty($peer['public_key']) || empty($peer['allowed_ips'])) {
                continue;
            }
            $lines[] = '';
            $lines[] = '[Peer]';
            $lines[] = 'PublicKey = ' . awg_sanitize($peer['public_key']);
            if (!empty($peer['preshared_key'])) {
                $lines[] = 'PresharedKey = ' . awg_sanitize($peer['preshared_key']);
            }
            $lines[] = 'AllowedIPs = ' . awg_sanitize($peer['allowed_ips']);
            if (!empty($peer['persistent_keepalive'])) {
                $lines[] = 'PersistentKeepalive = ' . awg_sanitize($peer['persistent_keepalive']);
            }
        }
    } else {
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = ' . awg_sanitize($inst['peer_public_key']);
        if (!empty($inst['peer_preshared_key'])) {
            $lines[] = 'PresharedKey = ' . awg_sanitize($inst['peer_preshared_key']);
        }
        $lines[] = 'Endpoint = '    . awg_sanitize($inst['peer_endpoint']);
        $lines[] = 'AllowedIPs = '  . awg_sanitize($inst['peer_allowed_ips']);
        if (!empty($inst['peer_persistent_keepalive'])) {
            $lines[] = 'PersistentKeepalive = ' . awg_sanitize($inst['peer_persistent_keepalive']);
        }
    }

    $conf = implode("\n", $lines) . "\n";
    $path = $pathOverride ?? (AWG_CONF_DIR . '/' . $inst['interface'] . '.conf');
    $dir = dirname($path);

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true)) {
            awg_log('ERROR: failed to create config directory: ' . $dir);
            return '';
        }
    }
    /*
     * Write atomically: never expose a partially-written canonical config.
     */
    if (!awg_atomic_write_bytes($path, $conf)) {
        return '';
    }

    return $path;
}

/**
 * Redact secrets from command output before it reaches logs or API responses.
 *
 * awg-quick strip emits PrivateKey/PresharedKey directives, so logging its
 * raw stdout would persist tunnel credentials in /var/log/amneziawg.log.
 */
function awg_redact_output(string $text): string
{
    return preg_replace(
        '/^(\s*(?:PrivateKey|PresharedKey)\s*=\s*).+$/mi',
        '$1(redacted)',
        $text
    ) ?? $text;
}

function awg_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    @file_put_contents('/var/log/amneziawg.log', "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

/**
 * Run a command with a timeout. Returns [output_string, return_code].
 * If the command exceeds $timeout seconds, it is killed and rc=124.
 */
function awg_exec_timeout(string $cmd, int $timeout = 30): array
{
    awg_log('EXEC: ' . $cmd);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        awg_log('EXEC ERROR: proc_open failed for: ' . $cmd);
        return ['proc_open failed', 1];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start  = time();
    $killed = false;

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // Process finished — read remaining output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            break;
        }
        if ((time() - $start) >= $timeout) {
            // Timeout — kill the process tree
            awg_log('EXEC TIMEOUT: ' . $timeout . 's exceeded, killing pid=' . $status['pid']);
            // proc_open() may launch a shell whose child is awg-quick. Kill
            // direct children first on FreeBSD, then the wrapper process.
            @exec('/usr/bin/pkill -KILL -P ' . (int)$status['pid'] . ' 2>/dev/null');
            @proc_terminate($proc, 9);
            $killed = true;
            break;
        }
        // Read available output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        usleep(100000); // 100ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $statusRc = isset($status['exitcode']) ? (int)$status['exitcode'] : -1;
    $closeRc = proc_close($proc);
    $rc = $killed ? 124 : ($statusRc >= 0 ? $statusRc : $closeRc);

    $output = trim($stdout . ($stderr ? "\n" . $stderr : ''));
    awg_log('EXEC DONE: rc=' . $rc . ' | ' . substr(awg_redact_output($output), 0, 200));
    return [$output, $rc];
}

// Multi-instance: sentinel management moved to awg_start_all()/awg_stop_all()
// (one service-level sentinel; per-tunnel start/stop here would thrash it).
function awg_up(array $inst): bool
{
    $iface = (string)$inst['interface'];

    // awg_up() is only called for instances resolved from the enabled
    // OPNsense model. Never replace an already-live interface here.
    $probe = [];
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1', $probe, $probeRc);
    if ($probeRc === 0) {
        awg_log('ERROR: refusing to replace already-live interface ' . $iface);
        return false;
    }

    $path = awg_write_conf($inst);
    if ($path === '') {
        awg_log('ERROR: failed to write config for ' . $iface . ', skipping up');
        return false;
    }

    [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' up ' . escapeshellarg($path) . ' 2>&1', 30);
    awg_log('up ' . $iface . ' rc=' . $rc . ' | ' . $output);

    if ($rc === 0) {
        exec('/usr/local/sbin/configctl -d interface newip '
            . escapeshellarg($iface) . ' >/dev/null 2>&1');
    }

    return $rc === 0;
}

/**
 * Start a sentinel process via daemon(8) so OPNsense Dashboard can track
 * service status through the PID file. AmneziaWG is a kernel module with
 * no long-running daemon, so we spawn a lightweight "sleep infinity" process.
 * daemon(8) writes the child PID to AWG_PID_FILE automatically.
 */
function awg_start_sentinel(): void
{
    // Kill any existing sentinel first
    awg_stop_sentinel();
    // FreeBSD sleep does not support "infinity" — use large value (~31 years)
    // Redirect to /dev/null: PHP exec() blocks until ALL pipe writers close,
    // and daemon's child (sleep) inherits the pipe, causing exec() to hang forever.
    exec('/usr/sbin/daemon -p ' . escapeshellarg(AWG_PID_FILE) . ' /bin/sleep 999999999 >/dev/null 2>&1', $out, $rc);
    if ($rc !== 0) {
        awg_log('WARNING: failed to start sentinel daemon rc=' . $rc . ': ' . implode(' ', $out));
    } else {
        // Wait briefly for daemon(8) to write PID file
        usleep(200000); // 200ms
        if (file_exists(AWG_PID_FILE)) {
            awg_log('Sentinel started, pid=' . trim(file_get_contents(AWG_PID_FILE)));
        } else {
            awg_log('WARNING: sentinel started but PID file not created');
        }
    }
}

/**
 * Stop sentinel process and remove PID file.
 */
function awg_stop_sentinel(): void
{
    if (file_exists(AWG_PID_FILE)) {
        $pid = (int)trim(@file_get_contents(AWG_PID_FILE));
        if ($pid > 0 && awg_pid_alive($pid)) {
            exec('kill ' . $pid . ' 2>/dev/null');
            usleep(100000); // 100ms
            if (awg_pid_alive($pid)) {
                exec('kill -9 ' . $pid . ' 2>/dev/null');
            }
        }
        @unlink(AWG_PID_FILE);
    }
}

/**
 * Ensure that the service sentinel exists without restarting a healthy one.
 *
 * The sentinel represents OPNsense service state only; it is not part of the
 * AmneziaWG data plane. Normal lifecycle reconciliation must therefore keep
 * an existing healthy sentinel PID intact.
 */
function awg_ensure_sentinel(): void
{
    if (file_exists(AWG_PID_FILE)) {
        $pid = (int)trim(@file_get_contents(AWG_PID_FILE));

        if ($pid > 0 && awg_pid_alive($pid)) {
            return;
        }

        awg_log(
            'Sentinel PID file is stale' .
            ($pid > 0 ? ', pid=' . $pid : '') .
            '; recreating'
        );
        @unlink(AWG_PID_FILE);
    }

    awg_start_sentinel();
}

/**
 * Return all interface names present in the OPNsense model, regardless of
 * enabled state. This is the persistent desired-state inventory used for
 * lifecycle reconciliation.
 */
function awg_get_configured_ifaces(): array|false
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $result = [];

    foreach (['instances' => 'instance', 'servers' => 'server'] as $containerName => $itemName) {
        $container = $config->OPNsense->amneziawg->{$containerName} ?? null;
        if (!isset($container) || !isset($container->{$itemName})) {
            continue;
        }

        foreach ($container->{$itemName} as $item) {
            $raw = trim((string)($item->interface_number ?? ''));
            $uuid = (string)($item->attributes()['uuid'] ?? '');
            $name = (string)($item->name ?? '');

            /*
             * Do not silently coerce missing or malformed model data to awg0.
             * The model normally guarantees 0..99, but lifecycle code must
             * fail closed if config.xml was damaged or bypassed the API.
             */
            if (!preg_match('/^(0|[1-9][0-9]?)$/', $raw)) {
                awg_log(
                    'ERROR: invalid interface_number in model: type=' .
                    $itemName . ' uuid=' . $uuid . ' name=' . $name .
                    ' value=' . ($raw === '' ? '<empty>' : $raw)
                );
                return false;
            }

            $number = (int)$raw;
            $iface = 'awg' . $number;

            /*
             * Client and server objects share one kernel awgN namespace.
             * Never allow associative-array overwrite to hide a collision.
             */
            if (isset($result[$iface])) {
                $previous = $result[$iface];
                awg_log(
                    'ERROR: interface collision in model: ' . $iface .
                    ' => ' .
                    ($previous['type'] ?? '?') . ':' .
                    ($previous['uuid'] ?? '?') .
                    ' (' . ($previous['name'] ?? '') . ')' .
                    ' <-> ' .
                    $itemName . ':' . $uuid .
                    ' (' . $name . ')'
                );
                return false;
            }

            $result[$iface] = [
                'enabled' => (string)($item->enabled ?? '0') === '1',
                'type' => $itemName,
                'uuid' => $uuid,
                'name' => $name,
            ];
        }
    }

    return $result;
}

function awg_down(array $inst): bool
{
    $iface = (string)$inst['interface'];
    $path = AWG_CONF_DIR . '/' . $iface . '.conf';

    $configured = awg_get_configured_ifaces();
    if ($configured === false) {
        awg_log(
            'ERROR: refusing to stop ' . $iface .
            ' because model inventory is invalid'
        );
        return false;
    }

    $owned = isset($configured[$iface]) || file_exists($path);

    if (!$owned) {
        awg_log('ERROR: refusing to stop unmanaged interface ' . $iface);
        return false;
    }

    $tmp = [];
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1', $tmp, $beforeRc);

    if ($beforeRc !== 0) {
        return true;
    }

    if (!file_exists($path)) {
        awg_log('ERROR: refusing to stop live ' . $iface . ' without canonical config ' . $path);
        return false;
    }

    [$output, $rc] = awg_exec_timeout(
        AWG_QUICK . ' down ' . escapeshellarg($path) . ' 2>&1',
        30
    );

    awg_log('down ' . $iface . ' rc=' . $rc . ' | ' . $output);

    $tmp = [];
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1', $tmp, $afterRc);

    if ($rc === 0 && $afterRc !== 0) {
        return true;
    }

    awg_log('ERROR: teardown not confirmed for ' . $iface . '; canonical config retained');
    return false;
}

function awg_count_up(): ?int
{
    $managed = awg_get_configured_ifaces();
    if ($managed === false) {
        awg_log(
            'ERROR: cannot count managed interfaces because model inventory is invalid'
        );
        return null;
    }

    $ifOut = [];
    exec('/sbin/ifconfig -l', $ifOut);
    $existing = array_flip(explode(' ', trim($ifOut[0] ?? '')));

    $count = 0;
    foreach ($managed as $iface => $_) {
        if (isset($existing[$iface])) {
            $count++;
        }
    }

    return $count;
}

// BUG-3: awg_is_up() was declared but never used — removed.

$action = $argv[1] ?? 'status';
awg_log('ACTION: ' . $action . ' (pid=' . getmypid() . ')');

// Catch fatal errors and log them
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        awg_log('PHP FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

// Helper: check if a PID is alive (works even without posix extension)
function awg_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }
    // Fallback: use kill -0 via shell
    exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

// Serialize lifecycle mutations with flock(2).
//
// The flock itself is authoritative. The PID stored in the file is diagnostic
// only: a persistent lock-file inode does not imply that its recorded PID
// still owns the lock, and PIDs may be reused by unrelated processes.
$lockActions = ['start', 'stop', 'restart', 'reconfigure', 'start_instance', 'stop_instance', 'restart_instance', 'sentinel_repair'];
$lockFp = null;
$lockFile = '/var/run/amneziawg.lock';

if (in_array($action, $lockActions, true)) {
    awg_log('LOCK: acquiring for ' . $action);

    $lockFp = fopen($lockFile, 'c');
    if ($lockFp === false) {
        awg_log('ERROR: cannot open lock file');
        echo "ERROR: cannot open lock file\n";
        exit(1);
    }

    chmod($lockFile, 0600);

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        rewind($lockFp);
        $holder = trim((string)stream_get_contents($lockFp));

        awg_log(
            'SKIP: lifecycle lock busy' .
            ($holder !== '' ? ' (recorded pid=' . $holder . ')' : '') .
            ', action=' . $action
        );

        echo "ERROR: lifecycle busy; another AmneziaWG action is already running\n";
        fclose($lockFp);
        exit(1);
    }

    ftruncate($lockFp, 0);
    rewind($lockFp);
    fwrite($lockFp, (string)getmypid());
    fflush($lockFp);

    awg_log('LOCK: acquired for ' . $action);
}

// Helper: bring down all awg interfaces
function awg_stop_all(): bool
{
    awg_log('awg_stop_all: starting');

    $targets = [];

    $configured = awg_get_configured_ifaces();
    if ($configured === false) {
        awg_log(
            'ERROR: awg_stop_all: refusing mutation because model inventory is invalid'
        );
        return false;
    }

    foreach ($configured as $iface => $_) {
        $targets[$iface] = true;
    }

    foreach (glob(AWG_CONF_DIR . '/awg*.conf') ?: [] as $conf) {
        $iface = basename($conf, '.conf');
        if (awg_valid_iface($iface)) {
            $targets[$iface] = true;
        }
    }

    $allOk = true;

    foreach (array_keys($targets) as $iface) {
        if (!awg_down(['interface' => $iface])) {
            $allOk = false;
        }
    }

    $managedUp = awg_count_up();
    if ($managedUp === null) {
        awg_log(
            'ERROR: managed interface count unavailable; leaving sentinel untouched'
        );
    } elseif ($managedUp > 0) {
        awg_ensure_sentinel();
    } else {
        awg_stop_sentinel();
    }

    awg_log('awg_stop_all: done, result=' . ($allOk ? 'ok' : 'failed'));
    return $allOk;
}

// Helper: bring up configured instances
function awg_start_all(): bool
{
    /*
     * Validate the complete client/server namespace before generating or
     * starting anything. This also protects direct service Start, which does
     * not pass through awg_reconcile().
     */
    if (awg_get_configured_ifaces() === false) {
        awg_log(
            'ERROR: awg_start_all: refusing mutation because model inventory is invalid'
        );
        return false;
    }

    $instances = awg_get_instances();

    if (empty($instances)) {
        awg_log('WARNING: no enabled instances to start');
        awg_stop_sentinel();
        return false;
    }

    $upCount = 0;

    foreach ($instances as $inst) {
        $iface = $inst['interface'];

        $ifOut = [];
        exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1', $ifOut, $ifRc);

        if ($ifRc === 0) {
            $canonical = AWG_CONF_DIR . '/' . $iface . '.conf';
            if (!file_exists($canonical)) {
                awg_log(
                    'ERROR: awg_start_all: live ' . $iface .
                    ' has no plugin canonical config; refusing to claim it'
                );
                continue;
            }
            // Enabled model object + canonical config + live interface:
            // idempotent managed success.
            $upCount++;
            continue;
        }

        if (awg_up($inst)) {
            $upCount++;
        }
    }

    if ($upCount > 0) {
        awg_ensure_sentinel();
    } else {
        awg_stop_sentinel();
    }

    return $upCount > 0 && $upCount === count($instances);
}

/**
 * Converge kernel/runtime state to the complete OPNsense model.
 *
 * Canonical awgN.conf files are persistent plugin-owned configuration.
 * A canonical config whose interface disappeared from the model is stale
 * ownership left by a Delete operation and is removed only after any live
 * interface has been successfully torn down.
 */
function awg_reconcile(): bool
{
    awg_log('RECONCILE: starting differential reconciliation');

    $configured = awg_get_configured_ifaces();
    if ($configured === false) {
        awg_log(
            'ERROR: RECONCILE: refusing mutation because model inventory is invalid'
        );
        return false;
    }

    $enabled = [];

    // awg_get_instances() contains only enabled client/server objects and,
    // unlike awg_get_configured_ifaces(), contains all data required to
    // generate the desired canonical configuration.
    foreach (awg_get_instances() as $inst) {
        $iface = (string)$inst['interface'];

        if (isset($enabled[$iface])) {
            awg_log('ERROR: RECONCILE: duplicate enabled interface in model: ' . $iface);
            return false;
        }

        $enabled[$iface] = $inst;
    }

    $allOk = true;

    /*
     * Phase 1: disabled configured interfaces.
     *
     * A disabled object remains in the OPNsense model and keeps its canonical
     * configuration, but its live kernel interface must be DOWN.
     *
     * Crucially, enabled interfaces are NOT touched here.
     */
    foreach ($configured as $iface => $state) {
        if (($state['enabled'] ?? false) === true) {
            continue;
        }

        $probe = [];
        exec(
            '/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1',
            $probe,
            $rc
        );

        if ($rc !== 0) {
            awg_log('RECONCILE: disabled ' . $iface . ' already down');
            continue;
        }

        awg_log('RECONCILE: stopping disabled ' . $iface);

        if (!awg_down(['interface' => $iface])) {
            awg_log('ERROR: RECONCILE: failed to stop disabled ' . $iface);
            $allOk = false;
        }
    }

    if (!$allOk) {
        awg_log('RECONCILE: aborting after disabled-interface teardown failure');
        return false;
    }

    /*
     * Phase 2: remove canonical configs belonging to deleted model objects.
     *
     * A stale live interface is torn down first using its existing canonical
     * configuration. Canonical config is removed only after teardown succeeds.
     */
    foreach (glob(AWG_CONF_DIR . '/awg*.conf') ?: [] as $conf) {
        $iface = basename($conf, '.conf');

        if (!awg_valid_iface($iface) || isset($configured[$iface])) {
            continue;
        }

        awg_log('RECONCILE: stale canonical config found for deleted ' . $iface);

        $probe = [];
        exec(
            '/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1',
            $probe,
            $rc
        );

        if ($rc === 0) {
            if (!awg_down(['interface' => $iface])) {
                awg_log(
                    'ERROR: RECONCILE: failed to stop stale live ' .
                    $iface . '; retaining canonical config'
                );
                $allOk = false;
                continue;
            }
        }

        if (!@unlink($conf)) {
            awg_log(
                'ERROR: RECONCILE: failed to remove stale canonical config ' .
                $conf
            );
            $allOk = false;
            continue;
        }

        awg_log('RECONCILE: removed stale canonical config ' . basename($conf));
        @unlink(awg_instance_stopped_flag($iface));
    }

    if (!$allOk) {
        awg_log('RECONCILE: aborting after stale cleanup failure');
        return false;
    }

    /*
     * Phase 3: enabled interfaces.
     *
     * For every enabled object generate the desired configuration into an
     * isolated temporary file and compare it byte-for-byte with the persistent
     * canonical awgN.conf.
     *
     *   live + identical config -> leave completely untouched
     *   live + changed config   -> down using old canonical, then start new
     *   down                    -> start from current model
     *
     * This is intentionally differential: applying a change to one tunnel must not
     * bounce any other unchanged tunnel.
     */
    foreach ($enabled as $iface => $inst) {
        $canonical = AWG_CONF_DIR . '/' . $iface . '.conf';

        $tmpDir = rtrim(sys_get_temp_dir(), '/')
            . '/amneziawg-reconcile-'
            . bin2hex(random_bytes(6));

        if (!mkdir($tmpDir, 0700, true)) {
            awg_log(
                'ERROR: RECONCILE: failed to create temporary directory for ' .
                $iface
            );
            $allOk = false;
            continue;
        }

        $desired = $tmpDir . '/' . $iface . '.conf';

        try {
            if (awg_write_conf($inst, $desired) === '') {
                awg_log(
                    'ERROR: RECONCILE: failed to generate desired config for ' .
                    $iface
                );
                $allOk = false;
                continue;
            }

            $probe = [];
            exec(
                '/sbin/ifconfig ' . escapeshellarg($iface) . ' >/dev/null 2>&1',
                $probe,
                $liveRc
            );

            $isLive = ($liveRc === 0);
            $canonicalExists = file_exists($canonical);
            $rollbackData = null;

            if ($isLive && $canonicalExists) {
                $rollbackData = file_get_contents($canonical);
                if ($rollbackData === false) {
                    awg_log(
                        'ERROR: RECONCILE: cannot snapshot canonical config before restart: ' .
                        $canonical
                    );
                    $allOk = false;
                    continue;
                }
            }

            $sameConfig = false;
            if ($canonicalExists) {
                $desiredHash = hash_file('sha256', $desired);
                $canonicalHash = hash_file('sha256', $canonical);

                $sameConfig =
                    $desiredHash !== false &&
                    $canonicalHash !== false &&
                    hash_equals($desiredHash, $canonicalHash);
            }

            if ($isLive && $sameConfig) {
                awg_log(
                    'RECONCILE: ' . $iface .
                    ' live and configuration unchanged — leaving untouched'
                );
                continue;
            }

            if ($isLive && !$canonicalExists) {
                /*
                 * Never destroy a live interface if we do not have the
                 * canonical file that awg-quick down needs. This is safer than
                 * trying to manufacture ownership/configuration after the fact.
                 */
                awg_log(
                    'ERROR: RECONCILE: ' . $iface .
                    ' is live but canonical config is missing; refusing teardown'
                );
                $allOk = false;
                continue;
            }

            if ($isLive) {
                awg_log(
                    'RECONCILE: ' . $iface .
                    ' configuration changed — restarting this interface only'
                );

                if (!awg_down(['interface' => $iface])) {
                    awg_log(
                        'ERROR: RECONCILE: failed to stop changed ' . $iface
                    );
                    $allOk = false;
                    continue;
                }
            } else {
                awg_log(
                    'RECONCILE: enabled ' . $iface .
                    ' is down — starting it'
                );
            }

            /*
             * awg_up() regenerates the canonical file from the current model
             * immediately before awg-quick up. Therefore the temporary desired
             * file is comparison-only and never becomes the live config.
             */
            if (!awg_up($inst)) {
                awg_log(
                    'ERROR: RECONCILE: failed to start enabled ' . $iface
                );
                $allOk = false;

                /*
                 * Transactional rollback for changed live interfaces.
                 * A bad edit must not strand a previously working tunnel DOWN.
                 */
                if ($isLive && $rollbackData !== null) {
                    awg_log(
                        'ROLLBACK: restoring previous canonical config for ' . $iface
                    );

                    if (!awg_atomic_write_bytes($canonical, $rollbackData)) {
                        awg_log(
                            'CRITICAL: ROLLBACK: failed to restore canonical config for ' .
                            $iface
                        );
                        continue;
                    }

                    [$rollbackOutput, $rollbackRc] = awg_exec_timeout(
                        AWG_QUICK . ' up ' . escapeshellarg($canonical) . ' 2>&1',
                        30
                    );

                    awg_log(
                        'ROLLBACK: up ' . $iface . ' rc=' . $rollbackRc .
                        ' | ' . $rollbackOutput
                    );

                    if ($rollbackRc === 0) {
                        exec(
                            '/usr/local/sbin/configctl -d interface newip ' .
                            escapeshellarg($iface) . ' >/dev/null 2>&1'
                        );
                        awg_log(
                            'ROLLBACK: previous working configuration restored for ' .
                            $iface
                        );
                    } else {
                        awg_log(
                            'CRITICAL: ROLLBACK: previous configuration could not be restarted for ' .
                            $iface
                        );
                    }
                }

                continue;
            }

            awg_log('RECONCILE: ' . $iface . ' successfully converged');
        } finally {
            @unlink($desired);
            @rmdir($tmpDir);
        }
    }

    /*
     * Keep the service sentinel synchronized without bouncing any tunnel.
     */
    $managedUp = awg_count_up();
    if ($managedUp === null) {
        awg_log(
            'ERROR: managed interface count unavailable; leaving sentinel untouched'
        );
    } elseif ($managedUp > 0) {
        awg_ensure_sentinel();
    } else {
        awg_stop_sentinel();
    }

    awg_log(
        'RECONCILE: differential reconciliation complete, result=' .
        ($allOk ? 'ok' : 'failed')
    );

    return $allOk;
}
switch ($action) {
    case 'start':
        if (!awg_service_enabled()) {
            echo "ERROR: AmneziaWG is disabled in General settings\n";
            break;
        }
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install opnsense-awg-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_awg kernel module not available. Install/reinstall opnsense-awg-kmod.\n";
            break;
        }
        // Remove stopped flags so watchdog can monitor
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        if (awg_start_all()) {
            awg_health_cache_invalidate_clients();
            echo "OK\n";
        } else {
            echo "ERROR: one or more enabled tunnels failed to start\n";
        }
        break;

    case 'stop':
        // Set stopped flag so watchdog doesn't auto-restart
        if (file_put_contents(AWG_STOPPED_FLAG, (string)getmypid()) === false) {
            awg_log('WARNING: failed to write stopped flag');
        }
        // Service-level flag covers everything — drop stale per-instance flags
        awg_clear_instance_stopped_flags();
        if (awg_stop_all()) {
            echo "OK\n";
        } else {
            echo "ERROR: one or more tunnels failed to stop\n";
        }
        break;

    case 'restart':
        if (!awg_service_enabled()) {
            echo "ERROR: AmneziaWG is disabled in General settings\n";
            break;
        }
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install opnsense-awg-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_awg kernel module not available. Install/reinstall opnsense-awg-kmod.\n";
            break;
        }
        // Remove stopped flags so watchdog can monitor
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        if (!awg_stop_all()) {
            echo "ERROR: one or more tunnels failed to stop before restart\n";
            break;
        }
        if (awg_start_all()) {
            awg_health_cache_invalidate_clients();
            echo "OK\n";
        } else {
            echo "ERROR: one or more enabled tunnels failed to restart\n";
        }
        break;

    case 'reconfigure':
        if (!awg_service_enabled()) {
            // Desired-state convergence: a disabled service must have no
            // plugin-owned live interfaces, even when reconfigure is invoked
            // directly through configctl instead of the GUI.
            if (file_put_contents(AWG_STOPPED_FLAG, (string)getmypid()) === false) {
                awg_log('WARNING: failed to write stopped flag');
            }
            awg_clear_instance_stopped_flags();
            if (awg_stop_all()) {
                echo "OK: disabled\n";
            } else {
                echo "ERROR: service is disabled but one or more tunnels failed to stop\n";
            }
            break;
        }
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install opnsense-awg-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_awg kernel module not available. Install/reinstall opnsense-awg-kmod.\n";
            break;
        }
        // Apply = make live state match config: clear manual-stop state too,
        // otherwise tunnels come up while watchdog still considers them stopped
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        if (awg_reconcile()) {
            echo "OK\n";
        } else {
            echo "ERROR: failed to reconcile configured tunnels\n";
        }
        break;

    case 'start_instance':
    case 'stop_instance':
    case 'restart_instance':
        $ifaceArg = $argv[2] ?? '';
        if (!awg_valid_iface($ifaceArg)) {
            awg_log('ERROR: invalid interface token: ' . substr($ifaceArg, 0, 32));
            echo "ERROR: invalid interface\n";
            break;
        }
        if ($action === 'start_instance' || $action === 'restart_instance') {
            if (!awg_service_enabled()) {
                echo "ERROR: AmneziaWG is disabled in General settings\n";
                break;
            }
            if (!awg_check_binaries() || !awg_check_kmod()) {
                echo "ERROR: binaries or kernel module not available\n";
                break;
            }
            $target = null;
            foreach (awg_get_instances() as $inst) {
                if ($inst['interface'] === $ifaceArg) {
                    $target = $inst;
                    break;
                }
            }
            if ($target === null) {
                echo "ERROR: no enabled instance for " . $ifaceArg . "\n";
                break;
            }
            // Manual per-row start/restart lifts both the per-instance
            // stop and a stale service-level stop. This is required after
            // transactional installer restore, which stops the whole service
            // before bringing back the interfaces that were previously live.
            @unlink(AWG_STOPPED_FLAG);
            @unlink(awg_instance_stopped_flag($ifaceArg));
            $probe = [];
            exec('/sbin/ifconfig ' . escapeshellarg($ifaceArg) . ' >/dev/null 2>&1', $probe, $probeRc);
            if ($action === 'restart_instance' && $probeRc === 0) {
                if (!awg_down(['interface' => $ifaceArg])) {
                    echo "ERROR: failed to stop " . $ifaceArg . " for restart\n";
                    break;
                }
                $probeRc = 1;
            }
            if ($probeRc === 0) {
                $canonical = AWG_CONF_DIR . '/' . $ifaceArg . '.conf';
                if (!file_exists($canonical)) {
                    awg_log(
                        'ERROR: start_instance refusing live ' . $ifaceArg .
                        ' because canonical config is missing'
                    );
                    echo "ERROR: live interface exists without plugin canonical config\n";
                    break;
                }
                // Enabled model object + canonical config + live interface:
                // idempotent managed success.
            } elseif (!awg_up($target)) {
                echo "ERROR: failed to start " . $ifaceArg . "\n";
                break;
            }
        } else {
            // Flag first so watchdog doesn't race a restart mid-teardown
            if (file_put_contents(awg_instance_stopped_flag($ifaceArg), (string)getmypid()) === false) {
                awg_log('WARNING: failed to write per-instance stopped flag for ' . $ifaceArg);
            }
            if (!awg_down(['interface' => $ifaceArg])) {
                echo "ERROR: failed to stop " . $ifaceArg . "\n";
                break;
            }
        }
        // Service-level sentinel follows the number of live managed tunnels.
        // Unknown inventory is fail-safe: never alter sentinel state.
        $managedUp = awg_count_up();
        if ($managedUp === null) {
            awg_log('ERROR: managed interface count unavailable; leaving sentinel untouched');
        } elseif ($managedUp > 0) {
            awg_ensure_sentinel();
        } else {
            awg_stop_sentinel();
        }
        awg_health_cache_invalidate_iface($ifaceArg);
        echo "OK\n";
        break;

    case 'sentinel_repair':
        // Re-sync the sentinel PID with the actual tunnel state without
        // touching any tunnel (used by watchdog when only the PID died).
        $managedUp = awg_count_up();
        if ($managedUp === null) {
            awg_log('ERROR: sentinel_repair cannot verify managed inventory; leaving sentinel untouched');
            echo "ERROR: managed interface inventory unavailable\n";
            break;
        } elseif ($managedUp > 0) {
            awg_ensure_sentinel();
        } else {
            awg_stop_sentinel();
        }
        echo "OK\n";
        break;

    case 'status':
        $tunnels = [];
        $configuredInventory = awg_get_configured_ifaces();
        $inventoryValid = ($configuredInventory !== false);
        $managedIfaces = $inventoryValid ? $configuredInventory : [];
        exec('/sbin/ifconfig -l', $out5);
        $existing = explode(' ', trim($out5[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $managed = isset($managedIfaces[$iface]);
                $awgShow = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' 2>/dev/null', $awgShow);
                // Newest peer handshake epoch (0 = never) — feeds the grid
                // runtime-status column 
                $hsOut = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null', $hsOut);
                $newest = 0;
                foreach ($hsOut as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    $ts = (int)($parts[1] ?? 0);
                    if ($ts > $newest) {
                        $newest = $ts;
                    }
                }
                // Per-peer runtime data for the Server Peers grid. Keep this
                // in the existing status call so the GUI does not spawn one configd
                // request per configured peer.
                $peerRuntime = [];
                $endpointOut = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' endpoints 2>/dev/null', $endpointOut);
                foreach ($endpointOut as $line) {
                    $parts = preg_split('/\s+/', trim($line), 2);
                    $key = (string)($parts[0] ?? '');
                    if ($key !== '') {
                        $peerRuntime[$key] = ['latest_handshake' => 0, 'endpoint' => (string)($parts[1] ?? '')];
                    }
                }
                foreach ($hsOut as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    $key = (string)($parts[0] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if (!isset($peerRuntime[$key])) {
                        $peerRuntime[$key] = ['latest_handshake' => 0, 'endpoint' => ''];
                    }
                    $peerRuntime[$key]['latest_handshake'] = (int)($parts[1] ?? 0);
                }
                $tunnels[] = [
                    'interface'        => $iface,
                    'managed'          => $managed,
                    'inventory_valid'  => $inventoryValid,
                    'up'               => true,
                    'latest_handshake' => $newest,
                    'peers'            => $peerRuntime,
                    'details'          => implode("\n", $awgShow),
                ];
            }
        }
        $running = !empty($tunnels);
        echo json_encode([
            'status'  => $running ? 'ok' : 'stopped',
            'tunnels' => $tunnels,
        ]) . "\n";
        break;

    case 'version':
        $ver = file_exists(AWG_VERSION_FILE) ? trim(file_get_contents(AWG_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    case 'gen_keypair':
        // HIGH-1/CRIT-3: validate shell_exec results — awg may not be installed or may fail
        $privkeyRaw = shell_exec(AWG_BIN . ' genkey 2>/dev/null');
        if ($privkeyRaw === null || trim($privkeyRaw) === '') {
            awg_log('ERROR: awg genkey returned empty result');
            echo json_encode(['status' => 'error', 'message' => 'Failed to generate private key — is opnsense-awg-tools installed?']) . "\n";
            break;
        }
        $privkey = trim($privkeyRaw);
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $privkey)) {
            awg_log('ERROR: awg genkey returned invalid Base64: ' . substr($privkey, 0, 10) . '...');
            echo json_encode(['status' => 'error', 'message' => 'Generated private key has invalid format']) . "\n";
            break;
        }
        $pubkeyRaw = shell_exec('echo ' . escapeshellarg($privkey) . ' | ' . AWG_BIN . ' pubkey 2>/dev/null');
        if ($pubkeyRaw === null || trim($pubkeyRaw) === '') {
            awg_log('ERROR: awg pubkey returned empty result');
            echo json_encode(['status' => 'error', 'message' => 'Failed to derive public key']) . "\n";
            break;
        }
        $pubkey = trim($pubkeyRaw);
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $pubkey)) {
            awg_log('ERROR: awg pubkey returned invalid Base64: ' . substr($pubkey, 0, 10) . '...');
            echo json_encode(['status' => 'error', 'message' => 'Derived public key has invalid format']) . "\n";
            break;
        }
        echo json_encode(['status' => 'ok', 'private_key' => $privkey, 'public_key' => $pubkey]) . "\n";
        break;

    case 'validate':
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_awg kernel module not available. Install/reinstall opnsense-awg-kmod.\n";
            break;
        }
        $instances = awg_get_instances();
        if (empty($instances)) {
            echo "ERROR: no enabled instances to validate\n";
            break;
        }
        $allOk = true;
        foreach ($instances as $inst) {
            // True dry-run: use an isolated 0700 temp directory and keep the
            // expected <iface>.conf basename for awg-quick. Never touch the
            // canonical persistent config or its mtime.
            $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/amneziawg-validate-' . bin2hex(random_bytes(6));
            if (!mkdir($tmpDir, 0700, true)) {
                awg_log('VALIDATE: failed to create temporary directory');
                echo "ERROR: failed to create validation workspace\n";
                $allOk = false;
                continue;
            }
            $confPath = $tmpDir . '/' . $inst['interface'] . '.conf';
            try {
                $written = awg_write_conf($inst, $confPath);
                if ($written === '') {
                    awg_log('VALIDATE: failed to generate config for ' . $inst['interface']);
                    echo "ERROR: failed to generate config\n";
                    $allOk = false;
                    continue;
                }
                [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' strip ' . escapeshellarg($confPath) . ' 2>&1', 10);
                if ($rc !== 0) {
                    $safeOutput = awg_redact_output($output);
                    awg_log('VALIDATE: config invalid for ' . $inst['interface'] . ': ' . $safeOutput);
                    echo "ERROR: config validation failed: " . $safeOutput . "\n";
                    $allOk = false;
                } else {
                    awg_log('VALIDATE: config OK for ' . $inst['interface']);
                }
            } finally {
                @unlink($confPath);
                @rmdir($tmpDir);
            }
        }
        if ($allOk) {
            echo "OK\n";
        }
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}

// IMP-10: release the exclusive lock
if ($lockFp !== null) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
