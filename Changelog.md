# Changelog

## Unreleased

- Installer now resolves the latest supported `opnsense-awg-kmod` and `opnsense-awg-tools` releases independently. Kernel-only updates no longer require republishing or renumbering the unchanged tools package, and userspace-only updates no longer require a matching kmod version bump.

## v2.0.3 — 2026-09-06

- Fixed generated Server Peer client configurations to honor the peer-specific PersistentKeepalive value before falling back to the server default.
- Updated QR rendering for the current OPNsense jquery.qrcode API.
- Fixed edit dialogs becoming unresponsive after being closed on touch browsers.
- Fixed repeated Server Peer editing by keeping the framework-managed peer.server field intact and using a synchronized UI selector.

## v2.0.2 — 2026-09-06

- Fixed PHP namespace escaping in installer state detection, preventing a spurious OPNsense crash-report entry during installation.


## v2.0.1 — 2026-09-06

- Fixed fresh installation on systems with no enabled AmneziaWG instances: installer postflight now accepts the backend's explicit `no enabled instances to validate` result while continuing to fail on all other validation errors.

## v2.0.0 — 2026-09-04

- Corrected installer postflight handling for deliberately stopped backends and added explicit verification of restored interfaces.
- AWG 3.1 on/off settings (`RandomTrailers`, `DisableCookies`) use native GUI checkboxes only; users cannot enter arbitrary boolean strings.

- Migrated the plugin backend to project-owned AmneziaWG 3.1 packages `opnsense-awg-kmod` and `opnsense-awg-tools`, while retaining compatibility with existing AWG 2.x configurations.
- Installer now performs guarded AWG2 → AWG3 package/module migration using the latest compatible 3.x releases, SHA256 verification, package-manifest validation, configuration/key preservation and rollback packages.
- Kernel module namespace changed from `if_amn` to `if_awg`; boot autoload and watchdog/service checks were updated accordingly.
- Added HeaderProtectionKey, ContentPaddingAddition, ranged timing controls, RandomTrailers and DisableCookies to client and server models/forms/config generation.
- Added ranged PersistentKeepalive support for client peers, server peers and generated client configurations.
- Server provisioning/QR export propagates AWG 3.1 interface parameters to generated clients.
- Import parser understands AWG 3.1 fields and serializes booleans using the supported `on`/`off` form.
- Removed the Test Connection button, API action, configd action and helper script. Diagnostics and configuration validation remain available.
- Existing v1.0.0 configuration and protected key files remain compatible.

All notable public releases of `opnsense-awg` are documented here.

## v1.0.0 — 2026-08-31

### Initial release

- First public release under the `opnsense-awg` project name.
- Based on the client implementation from [MrTheory/os-amneziawg](https://github.com/MrTheory/os-amneziawg), retaining the upstream BSD 2-Clause license and copyright notice.
- Multi-instance AmneziaWG client support using the shared `awg0`–`awg99` namespace.
- Native `.conf` import, key generation, AWG 2.0 obfuscation/CPS fields, diagnostics, watchdog/sentinel handling and OPNsense selective-routing integration.
- Native AmneziaWG Server instances and multiple Server Peers.
- Server Peer client provisioning with generated client keypair/PSK, protected private-key storage, native `.conf` export and multipart QR import for AmneziaVPN.
- Shared client/server interface-number validation to prevent `awgN` namespace collisions.
- Per-instance Start / Stop / Restart and runtime status handling for both client and server instances.
- Differential Apply/Reconfigure: unchanged live tunnels remain untouched; changed tunnels are restarted individually; failed changed-config restarts attempt rollback to the previous canonical config.
- Persistent plugin-owned canonical configs under `/usr/local/etc/amnezia/awgN.conf`, written atomically and protected against unmanaged-interface takeover.
- Granular watchdog recovery for managed client/server interfaces.
- Protected client, server and provisioned-peer private keys stored outside `config.xml`.
- Hardened installer with repository-state verification, isolated command-scoped FreeBSD quarterly repository access, package-lock preservation and guarded `amnezia-tools` / `amnezia-kmod` update paths.
- Local rollback packages for AWG package replacement and capture/restore of the exact live managed interface set during kernel-module transactions.
- Safe uninstall flow with independent choices for AWG package removal and configuration/private-key purge.
- OPNsense 26.7.x / FreeBSD 15.1 as the primary target platform.
