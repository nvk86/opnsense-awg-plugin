#!/bin/sh
# opnsense-awg v2.0.3 installer
# Migrates the legacy FreeBSD amnezia-kmod/amnezia-tools AWG2 stack to the
# project-owned AWG 3.1 packages, preserving OPNsense configuration and keys.
set -eu

PLUGIN_VERSION="2.0.3"
KMOD_REPO="nvk86/opnsense-awg-kmod"
TOOLS_REPO="nvk86/opnsense-awg-tools"
KMOD_VERSION=""
TOOLS_VERSION=""
KMOD_PKG=""
TOOLS_PKG=""
KMOD_URL=""
TOOLS_URL=""
KMOD_SHA_URL=""
TOOLS_SHA_URL=""

SELF_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_DIR="$SELF_DIR/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt"
TXN_DIR=""
PKG_BIN=""
MUTATED=0
COMMITTED=0
WAS_ENABLED=0
RUNNING_IFACES=""
OLD_IF_AMN=0
OLD_IF_AWG=0
MIGRATION_TEST=0
FORCE_TEST_FAILURE=0

log(){ printf '%s\n' "$*"; }
warn(){ printf 'WARNING: %s\n' "$*" >&2; }
die(){ printf 'ERROR: %s\n' "$*" >&2; exit 1; }

need_root(){ [ "$(id -u)" -eq 0 ] || die "Run this installer as root."; }
choose_pkg(){
    if [ -x /usr/local/sbin/pkg-static ]; then PKG_BIN=/usr/local/sbin/pkg-static
    elif [ -x /usr/local/sbin/pkg ]; then PKG_BIN=/usr/local/sbin/pkg
    else die "pkg is not available"; fi
}
pkgq(){ "$PKG_BIN" "$@"; }
have_pkg(){ pkgq info "$1" >/dev/null 2>&1; }

resolve_latest_release(){
    _repo="$1"
    _prefix="$2"
    _json=$(mktemp /tmp/opnsense-awg-release.XXXXXX) || die "Could not create release metadata temporary file"
    fetch -q -o "$_json" "https://api.github.com/repos/${_repo}/releases/latest" || { rm -f "$_json"; die "Failed to resolve latest release for $_repo"; }
    _resolved=$(/usr/local/bin/php -r '
        $j = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($j) || empty($j["assets"]) || !is_array($j["assets"])) exit(2);
        $prefix = $argv[2];
        $pkg = null; $sha = null; $version = null;
        foreach ($j["assets"] as $a) {
            $name = $a["name"] ?? "";
            $url = $a["browser_download_url"] ?? "";
            if (preg_match("/^" . preg_quote($prefix, "/") . "-(.+)\\.pkg$/", $name, $m)) {
                if ($pkg !== null) exit(3);
                $pkg = $url; $version = $m[1];
            }
        }
        if ($pkg === null || $version === null) exit(4);
        $shaName = $prefix . "-" . $version . ".pkg.sha256";
        foreach ($j["assets"] as $a) {
            if (($a["name"] ?? "") === $shaName) {
                if ($sha !== null) exit(5);
                $sha = $a["browser_download_url"] ?? "";
            }
        }
        if ($sha === null || $sha === "") exit(6);
        echo $version, "|", $pkg, "|", $sha;
    ' "$_json" "$_prefix" 2>/dev/null) || { rm -f "$_json"; die "Latest release for $_repo does not contain one matching .pkg and .pkg.sha256 pair"; }
    rm -f "$_json"
    printf '%s\n' "$_resolved"
}

resolve_packages(){
    command -v /usr/local/bin/php >/dev/null 2>&1 || die "php is required to resolve GitHub release metadata"
    log "==> Resolving latest compatible AWG 3.x packages..."

    _k=$(resolve_latest_release "$KMOD_REPO" "opnsense-awg-kmod")
    KMOD_VERSION=${_k%%|*}
    _rest=${_k#*|}; KMOD_URL=${_rest%%|*}; KMOD_SHA_URL=${_rest#*|}

    _t=$(resolve_latest_release "$TOOLS_REPO" "opnsense-awg-tools")
    TOOLS_VERSION=${_t%%|*}
    _rest=${_t#*|}; TOOLS_URL=${_rest%%|*}; TOOLS_SHA_URL=${_rest#*|}

    case "$KMOD_VERSION" in 3.*) ;; *) die "Latest kmod release $KMOD_VERSION is outside supported AWG 3.x" ;; esac
    case "$TOOLS_VERSION" in 3.*) ;; *) die "Latest tools release $TOOLS_VERSION is outside supported AWG 3.x" ;; esac

    KMOD_PKG="opnsense-awg-kmod-${KMOD_VERSION}.pkg"
    TOOLS_PKG="opnsense-awg-tools-${TOOLS_VERSION}.pkg"
    log "[OK] Latest kmod:  $KMOD_VERSION"
    log "[OK] Latest tools: $TOOLS_VERSION"
}

check_platform(){
    [ "$(uname -s)" = "FreeBSD" ] || die "This installer is for FreeBSD/OPNsense only."
    _k=$(uname -K 2>/dev/null || echo 0)
    [ "$_k" -ge 1501000 ] || die "AWG 3.1 package requires FreeBSD 15.1 kernel ABI (uname -K >= 1501000)."
    if [ "$MIGRATION_TEST" -eq 0 ]; then
        [ -d /usr/local/opnsense ] || die "OPNsense installation not found. Use --migration-test only on a disposable FreeBSD 15.1 test host."
    fi
    command -v fetch >/dev/null 2>&1 || die "fetch(1) is required."
    command -v sha256 >/dev/null 2>&1 || die "sha256(1) is required."
}

read_state(){
    if [ -x /usr/local/bin/php ]; then
        WAS_ENABLED=$(/usr/local/bin/php -r 'require_once("/usr/local/etc/inc/config.inc"); $c=OPNsense\Core\Config::getInstance()->object(); echo ((string)($c->OPNsense->amneziawg->general->enabled ?? "0") === "1") ? "1" : "0";' 2>/dev/null || echo 0)
    fi
    if [ -x /usr/local/bin/awg ]; then RUNNING_IFACES=$(/usr/local/bin/awg show interfaces 2>/dev/null || true); fi
    /sbin/kldstat -q -m if_amn >/dev/null 2>&1 && OLD_IF_AMN=1 || true
    /sbin/kldstat -q -m if_awg >/dev/null 2>&1 && OLD_IF_AWG=1 || true
}

backup_file_tree(){
    mkdir -p "$TXN_DIR/rootfs"
    # Package-migration test hosts are plain FreeBSD, not OPNsense. Back up only
    # state touched by the package/module migration in that mode.
    if [ "$MIGRATION_TEST" -eq 1 ]; then
        [ -f /boot/loader.conf ] && cp -p /boot/loader.conf "$TXN_DIR/loader.conf"
        if [ -d /usr/local/etc/amnezia ]; then cp -Rp /usr/local/etc/amnezia "$TXN_DIR/amnezia"; fi
        return 0
    fi
    for _p in \
      /usr/local/opnsense/scripts/AmneziaWG \
      /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG \
      /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG \
      /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG \
      /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf \
      /usr/local/etc/inc/plugins.inc.d/amneziawg.inc \
      /usr/local/etc/rc.syshook.d/start/50-amneziawg \
      /etc/newsyslog.conf.d/amneziawg.conf; do
        if [ -e "$_p" ]; then
            mkdir -p "$TXN_DIR/rootfs$(dirname "$_p")"
            cp -Rp "$_p" "$TXN_DIR/rootfs$_p"
        fi
    done
    [ -f /conf/config.xml ] && cp -p /conf/config.xml "$TXN_DIR/config.xml"
    [ -f /boot/loader.conf ] && cp -p /boot/loader.conf "$TXN_DIR/loader.conf"
    if [ -d /usr/local/etc/amnezia ]; then cp -Rp /usr/local/etc/amnezia "$TXN_DIR/amnezia"; fi
}

backup_packages(){
    mkdir -p "$TXN_DIR/packages"
    for _pkg in amnezia-tools amnezia-kmod opnsense-awg-tools opnsense-awg-kmod; do
        if have_pkg "$_pkg"; then
            pkgq create -o "$TXN_DIR/packages" "$_pkg" >/dev/null || die "Could not create rollback package for $_pkg"
        fi
    done
}

download_packages(){
    mkdir -p "$TXN_DIR/new"
    log "==> Downloading resolved AWG release packages and checksums..."
    fetch -q -o "$TXN_DIR/new/$KMOD_PKG" "$KMOD_URL" || die "Failed to download $KMOD_PKG"
    fetch -q -o "$TXN_DIR/new/$KMOD_PKG.sha256" "$KMOD_SHA_URL" || die "Failed to download $KMOD_PKG.sha256"
    fetch -q -o "$TXN_DIR/new/$TOOLS_PKG" "$TOOLS_URL" || die "Failed to download $TOOLS_PKG"
    fetch -q -o "$TXN_DIR/new/$TOOLS_PKG.sha256" "$TOOLS_SHA_URL" || die "Failed to download $TOOLS_PKG.sha256"

    _ks=$(grep -Eo '[0-9a-fA-F]{64}' "$TXN_DIR/new/$KMOD_PKG.sha256" | head -n 1 | tr 'A-F' 'a-f' || true)
    _ts=$(grep -Eo '[0-9a-fA-F]{64}' "$TXN_DIR/new/$TOOLS_PKG.sha256" | head -n 1 | tr 'A-F' 'a-f' || true)
    [ ${#_ks} -eq 64 ] || die "Invalid SHA256 file for $KMOD_PKG"
    [ ${#_ts} -eq 64 ] || die "Invalid SHA256 file for $TOOLS_PKG"
    [ "$(sha256 -q "$TXN_DIR/new/$KMOD_PKG")" = "$_ks" ] || die "SHA256 mismatch for $KMOD_PKG"
    [ "$(sha256 -q "$TXN_DIR/new/$TOOLS_PKG")" = "$_ts" ] || die "SHA256 mismatch for $TOOLS_PKG"

    _km=$(pkgq info -F "$TXN_DIR/new/$KMOD_PKG" 2>/dev/null | awk '/^Name/{n=$3}/^Version/{v=$3}END{print n" "v}')
    _tl=$(pkgq info -F "$TXN_DIR/new/$TOOLS_PKG" 2>/dev/null | awk '/^Name/{n=$3}/^Version/{v=$3}END{print n" "v}')
    [ "$_km" = "opnsense-awg-kmod $KMOD_VERSION" ] || die "Unexpected kmod package manifest: $_km"
    [ "$_tl" = "opnsense-awg-tools $TOOLS_VERSION" ] || die "Unexpected tools package manifest: $_tl"
    log "[OK] Release assets, checksum files and package manifests verified"
}

stop_runtime(){
    if [ "$MIGRATION_TEST" -eq 1 ]; then
        log "==> Checking that no AWG interfaces are active on the migration-test host..."
    else
        log "==> Stopping plugin-managed tunnels..."
    fi
    if [ "$MIGRATION_TEST" -eq 0 ] && [ -x /usr/local/sbin/configctl ] && [ -f /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf ]; then
        /usr/local/sbin/configctl amneziawg stop >/dev/null 2>&1 || true
    fi
    # Refuse to replace a kernel module while any AWG interface remains. This also
    # protects foreign/manual awgN interfaces that are not owned by the plugin.
    if [ -x /usr/local/bin/awg ]; then
        _left=$(/usr/local/bin/awg show interfaces 2>/dev/null || true)
        [ -z "$_left" ] || die "AWG interfaces remain active: $_left. Bring them down before module migration."
    fi
}

remove_loader_entries(){
    touch /boot/loader.conf
    sed -i '' '/^[[:space:]]*if_amn_load=/d;/^[[:space:]]*if_awg_load=/d' /boot/loader.conf
}

migrate_packages(){
    MUTATED=1
    if /sbin/kldstat -q -m if_awg >/dev/null 2>&1; then /sbin/kldunload if_awg >/dev/null 2>&1 || die "Could not unload if_awg"; fi
    if /sbin/kldstat -q -m if_amn >/dev/null 2>&1; then /sbin/kldunload if_amn >/dev/null 2>&1 || die "Could not unload legacy if_amn"; fi

    for _pkg in amnezia-tools amnezia-kmod; do
        if have_pkg "$_pkg"; then
            pkgq unlock -qy "$_pkg" >/dev/null 2>&1 || true
            pkgq delete -y "$_pkg" >/dev/null || die "Failed to remove legacy $_pkg"
        fi
    done
    # Reinstall the independently resolved project packages even on a v2 repair
    # run; this removes uncertainty about locally modified package files.
    for _pkg in opnsense-awg-tools opnsense-awg-kmod; do
        if have_pkg "$_pkg"; then pkgq unlock -qy "$_pkg" >/dev/null 2>&1 || true; pkgq delete -y "$_pkg" >/dev/null || die "Failed to replace $_pkg"; fi
    done

    pkgq add "$TXN_DIR/new/$TOOLS_PKG" >/dev/null || die "Failed to install $TOOLS_PKG"
    pkgq add "$TXN_DIR/new/$KMOD_PKG" >/dev/null || die "Failed to install $KMOD_PKG"
    [ "$(pkgq query '%v' opnsense-awg-tools 2>/dev/null)" = "$TOOLS_VERSION" ] || die "Wrong opnsense-awg-tools version after install"
    [ "$(pkgq query '%v' opnsense-awg-kmod 2>/dev/null)" = "$KMOD_VERSION" ] || die "Wrong opnsense-awg-kmod version after install"
    /sbin/kldload /boot/modules/if_awg.ko >/dev/null 2>&1 || /sbin/kldstat -q -m if_awg >/dev/null 2>&1 || die "if_awg failed to load"
    remove_loader_entries
    printf '%s\n' 'if_awg_load="YES"' >> /boot/loader.conf
    log "[OK] AWG 3.1 packages installed and if_awg loaded"
}

remove_plugin_files(){
    rm -rf /usr/local/opnsense/scripts/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
    rm -f /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf
    rm -f /usr/local/etc/inc/plugins.inc.d/amneziawg.inc
    rm -f /usr/local/etc/rc.syshook.d/start/50-amneziawg
    rm -f /etc/newsyslog.conf.d/amneziawg.conf
}

install_plugin(){
    log "==> Installing opnsense-awg v$PLUGIN_VERSION files..."
    remove_plugin_files
    install -d /usr/local/opnsense/scripts/AmneziaWG
    for _f in "$PLUGIN_DIR"/scripts/AmneziaWG/*; do install -m 0755 "$_f" /usr/local/opnsense/scripts/AmneziaWG/; done
    install -d /usr/local/opnsense/service/conf/actions.d
    install -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_amneziawg.conf" /usr/local/opnsense/service/conf/actions.d/
    install -d /usr/local/opnsense/mvc/app/models/OPNsense
    cp -Rp "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG" /usr/local/opnsense/mvc/app/models/OPNsense/
    find /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG -type f -exec chmod 0644 {} \;
    install -d /usr/local/opnsense/mvc/app/controllers/OPNsense
    cp -Rp "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG" /usr/local/opnsense/mvc/app/controllers/OPNsense/
    find /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG -type f -exec chmod 0644 {} \;
    install -d /usr/local/opnsense/mvc/app/views/OPNsense
    cp -Rp "$PLUGIN_DIR/mvc/app/views/OPNsense/AmneziaWG" /usr/local/opnsense/mvc/app/views/OPNsense/
    find /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG -type f -exec chmod 0644 {} \;
    install -d /usr/local/etc/inc/plugins.inc.d /usr/local/etc/rc.syshook.d/start /etc/newsyslog.conf.d
    install -m 0644 "$PLUGIN_DIR/etc/inc/plugins.inc.d/amneziawg.inc" /usr/local/etc/inc/plugins.inc.d/
    install -m 0755 "$PLUGIN_DIR/etc/rc.syshook.d/start/50-amneziawg" /usr/local/etc/rc.syshook.d/start/
    install -m 0644 "$PLUGIN_DIR/etc/newsyslog.conf.d/amneziawg.conf" /etc/newsyslog.conf.d/
    install -d -m 0700 /usr/local/etc/amnezia
    rm -f /usr/local/opnsense/scripts/AmneziaWG/amneziawg-testconnect.php
    log "[OK] Plugin files installed"
}

run_migrations(){
    if [ -x /usr/local/bin/php ] && [ -f /usr/local/opnsense/mvc/script/run_migrations.php ]; then
        (cd /usr/local/opnsense/mvc && /usr/local/bin/php script/run_migrations.php) >/tmp/amneziawg-migrations.log 2>&1 || {
            tail -n 50 /tmp/amneziawg-migrations.log >&2 || true
            die "OPNsense model migrations failed"
        }
    fi
}

postflight(){
    service configd restart >/dev/null
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
    [ -x /usr/local/bin/awg ] || die "awg binary missing after install"
    [ -x /usr/local/bin/awg-quick ] || die "awg-quick binary missing after install"
    /sbin/kldstat -q -m if_awg >/dev/null 2>&1 || die "if_awg not loaded"
    _tools_upstream=${TOOLS_VERSION%%_*}
    /usr/local/bin/awg --version 2>/dev/null | grep -q "$_tools_upstream" || die "Unexpected awg userspace version"
    grep -q '\[testconnect\]' /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf && die "Obsolete testconnect action is still installed"
    grep -Rqs 'if_amn' /usr/local/opnsense/scripts/AmneziaWG /usr/local/etc/rc.syshook.d/start/50-amneziawg && die "Legacy if_amn reference remains in runtime scripts"
    # A healthy backend can legitimately report either "stopped" (no live
    # tunnels yet) or "ok".  At this point the installer intentionally stopped
    # the pre-upgrade interfaces and has not restored them yet, so requiring
    # only "ok" would make a normal upgrade fail before restoration.
    _st=$(/usr/local/sbin/configctl amneziawg status 2>&1 || true)
    printf '%s\n' "$_st" | grep -Eq '"status":"(ok|stopped)"' || die "configd status smoke test failed: $_st"
    _va=$(/usr/local/sbin/configctl amneziawg validate 2>&1 || true)
    if printf '%s\n' "$_va" | grep -q 'ERROR: no enabled instances to validate'; then
        log "[OK] No enabled AWG instances configured yet; skipping configuration validation"
    elif printf '%s\n' "$_va" | grep -Eq '^OK([[:space:]]|$)'; then
        :
    else
        die "configuration validation failed: $_va"
    fi

    mkdir -p "$(dirname "$VERSION_FILE")"
    printf '%s\n' "$PLUGIN_VERSION" > "$VERSION_FILE"
    chmod 0644 "$VERSION_FILE"

    # Restore exactly the interfaces that were live before migration.
    for _iface in $RUNNING_IFACES; do
        /usr/local/sbin/configctl amneziawg start_instance "$_iface" >/dev/null 2>&1 || die "Failed to restore previously running $_iface"
        /sbin/ifconfig "$_iface" >/dev/null 2>&1 || die "Previously running $_iface was not restored"
    done
    if [ -n "$RUNNING_IFACES" ]; then
        _st=$(/usr/local/sbin/configctl amneziawg status 2>&1 || true)
        printf '%s\n' "$_st" | grep -q '"status":"ok"' || die "restored tunnel status check failed: $_st"
    fi

    COMMITTED=1
    log "[OK] Backend, module, package versions, configuration and previous runtime state validated"
}

migration_test_postflight(){
    log "==> Running package/module migration smoke tests..."
    have_pkg amnezia-tools && die "Legacy amnezia-tools is still installed"
    have_pkg amnezia-kmod && die "Legacy amnezia-kmod is still installed"
    [ "$(pkgq query '%v' opnsense-awg-tools 2>/dev/null)" = "$TOOLS_VERSION" ] || die "Wrong opnsense-awg-tools version after migration"
    [ "$(pkgq query '%v' opnsense-awg-kmod 2>/dev/null)" = "$KMOD_VERSION" ] || die "Wrong opnsense-awg-kmod version after migration"
    /sbin/kldstat -q -m if_awg >/dev/null 2>&1 || die "if_awg not loaded after migration"
    ! /sbin/kldstat -q -m if_amn >/dev/null 2>&1 || die "Legacy if_amn is still loaded"
    _tools_upstream=${TOOLS_VERSION%%_*}
    /usr/local/bin/awg --version 2>/dev/null | grep -q "$_tools_upstream" || die "Unexpected awg userspace version"
    pkgq which /usr/local/bin/awg 2>/dev/null | grep -q 'opnsense-awg-tools-' || die "awg is not owned by opnsense-awg-tools"
    pkgq which /boot/modules/if_awg.ko 2>/dev/null | grep -q 'opnsense-awg-kmod-' || die "if_awg.ko is not owned by opnsense-awg-kmod"

    _smoke=awg98
    if ifconfig "$_smoke" >/dev/null 2>&1; then die "Smoke-test interface $_smoke already exists"; fi
    _created=0
    if ifconfig awg create name "$_smoke" >/dev/null 2>&1; then _created=1; else die "if_awg cloner smoke test failed"; fi
    /usr/local/bin/awg show interfaces 2>/dev/null | tr ' ' '\n' | grep -qx "$_smoke" || {
        [ "$_created" -eq 1 ] && ifconfig "$_smoke" destroy >/dev/null 2>&1 || true
        die "awg userspace cannot see $_smoke"
    }
    /usr/local/bin/awg set "$_smoke" h1 1 h2 2 h3 3 h4 4 >/dev/null 2>&1 || {
        ifconfig "$_smoke" destroy >/dev/null 2>&1 || true
        die "AWG default magic-header compatibility smoke test failed"
    }
    ifconfig "$_smoke" destroy >/dev/null 2>&1 || die "Could not destroy $_smoke after smoke test"

    COMMITTED=1
    log "[OK] Legacy packages removed, project packages installed, if_awg loaded, ownership and cloner ABI verified"
}

restore_rootfs(){
    if [ "$MIGRATION_TEST" -eq 0 ]; then remove_plugin_files; fi
    if [ -d "$TXN_DIR/rootfs" ]; then (cd "$TXN_DIR/rootfs" && tar -cf - .) | (cd / && tar -xpf -) || true; fi
    if [ "$MIGRATION_TEST" -eq 0 ] && [ -f "$TXN_DIR/config.xml" ]; then cp -p "$TXN_DIR/config.xml" /conf/config.xml; fi
    if [ -f "$TXN_DIR/loader.conf" ]; then cp -p "$TXN_DIR/loader.conf" /boot/loader.conf; fi
    if [ -d "$TXN_DIR/amnezia" ]; then rm -rf /usr/local/etc/amnezia; cp -Rp "$TXN_DIR/amnezia" /usr/local/etc/amnezia; fi
}

rollback(){
    [ "$MUTATED" -eq 1 ] || return 0
    warn "Installation failed; rolling back the previous AWG stack and plugin."
    if [ "$MIGRATION_TEST" -eq 0 ] && [ -x /usr/local/sbin/configctl ]; then /usr/local/sbin/configctl amneziawg stop >/dev/null 2>&1 || true; fi
    if [ -x /usr/local/bin/awg ]; then
        for _i in $(/usr/local/bin/awg show interfaces 2>/dev/null || true); do /usr/local/bin/awg-quick down "$_i" >/dev/null 2>&1 || ifconfig "$_i" destroy >/dev/null 2>&1 || true; done
    fi
    /sbin/kldunload if_awg >/dev/null 2>&1 || true
    for _pkg in opnsense-awg-tools opnsense-awg-kmod amnezia-tools amnezia-kmod; do
        if have_pkg "$_pkg"; then pkgq unlock -qy "$_pkg" >/dev/null 2>&1 || true; pkgq delete -y "$_pkg" >/dev/null 2>&1 || true; fi
    done
    if [ -d "$TXN_DIR/packages" ]; then
        for _f in "$TXN_DIR"/packages/*.pkg; do [ -f "$_f" ] || continue; pkgq add "$_f" >/dev/null 2>&1 || warn "Could not restore package $_f"; done
    fi
    restore_rootfs
    if [ "$OLD_IF_AMN" -eq 1 ]; then /sbin/kldload if_amn >/dev/null 2>&1 || warn "Could not reload legacy if_amn"; fi
    if [ "$OLD_IF_AWG" -eq 1 ]; then /sbin/kldload /boot/modules/if_awg.ko >/dev/null 2>&1 || warn "Could not reload previous if_awg"; fi
    if [ "$MIGRATION_TEST" -eq 0 ]; then
        service configd restart >/dev/null 2>&1 || true
        for _iface in $RUNNING_IFACES; do /usr/local/sbin/configctl amneziawg start_instance "$_iface" >/dev/null 2>&1 || true; done
    fi
    warn "Rollback completed. Recovery directory retained: $TXN_DIR"
}

on_exit(){
    _rc=$?
    trap - EXIT HUP INT TERM
    if [ "$_rc" -ne 0 ] && [ "$COMMITTED" -ne 1 ]; then rollback; fi
    if [ "$_rc" -eq 0 ] && [ "$COMMITTED" -eq 1 ] && [ -n "$TXN_DIR" ]; then rm -rf "$TXN_DIR"; fi
    exit "$_rc"
}

uninstall(){
    need_root; choose_pkg
    if [ -x /usr/local/sbin/configctl ]; then /usr/local/sbin/configctl amneziawg stop >/dev/null 2>&1 || true; fi
    remove_plugin_files
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
    service configd restart >/dev/null 2>&1 || true
    log "opnsense-awg plugin removed. AWG packages and /usr/local/etc/amnezia were intentionally kept."
    exit 0
}

case "${1:-}" in
    --uninstall) uninstall ;;
    --migration-test) MIGRATION_TEST=1 ;;
    --migration-test-fail) MIGRATION_TEST=1; FORCE_TEST_FAILURE=1 ;;
    "") ;;
    *) die "Usage: $0 [--uninstall|--migration-test|--migration-test-fail]" ;;
esac

need_root
choose_pkg
check_platform
if [ "$MIGRATION_TEST" -eq 0 ]; then
    [ -d "$PLUGIN_DIR" ] || die "plugin/ directory is missing next to install.sh"
else
    have_pkg amnezia-tools || die "--migration-test requires legacy amnezia-tools to be installed first"
    have_pkg amnezia-kmod || die "--migration-test requires legacy amnezia-kmod to be installed first"
fi
TXN_DIR=$(mktemp -d /tmp/opnsense-awg-v2.XXXXXX)
trap on_exit EXIT HUP INT TERM

log "============================================================"
if [ "$MIGRATION_TEST" -eq 1 ]; then
    log " opnsense-awg AWG2 -> AWG3 PACKAGE MIGRATION TEST"
else
    log " opnsense-awg v$PLUGIN_VERSION / AWG latest compatible 3.x"
fi
log "============================================================"

read_state
resolve_packages
backup_file_tree
backup_packages
download_packages
stop_runtime
migrate_packages
if [ "$MIGRATION_TEST" -eq 1 ]; then
    [ "$FORCE_TEST_FAILURE" -eq 0 ] || die "Forced failure after package migration (rollback test)"
    migration_test_postflight
    log ""
    log "AWG package migration test completed successfully."
    log "Legacy amnezia-tools/amnezia-kmod were replaced by the project-owned AWG 3.1 packages."
else
    install_plugin
    run_migrations
    postflight
    log ""
    log "opnsense-awg v$PLUGIN_VERSION installed successfully."
    log "Legacy amnezia-tools/amnezia-kmod have been replaced by the project-owned AWG 3.1 packages."
fi
