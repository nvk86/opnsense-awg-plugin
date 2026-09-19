# opnsense-awg-plugin

**AmneziaWG Client + Server plugin for OPNsense**

`opnsense-awg-plugin` integrates AmneziaWG into OPNsense as a native VPN service with multiple client tunnels, native server instances, server peers, client provisioning, selective routing, active data-plane health monitoring and managed service lifecycle.

The plugin supports **AmneziaWG 3.1** while retaining compatibility with existing **AWG 2.x** configurations.

AmneziaWG 3.1 support includes:

- Header Protection;
- Content Padding Addition;
- ranged protocol timers;
- ranged Persistent Keepalive;
- Random Trailers;
- Disable Cookies;
- client and server configuration import/export;
- native client and server operation using the `if_awg` kernel module.

The plugin uses the project-maintained [`opnsense-awg-kmod`](https://github.com/nvk86/opnsense-awg-kmod) and [`opnsense-awg-tools`](https://github.com/nvk86/opnsense-awg-tools) packages for the FreeBSD/OPNsense backend.

Existing installations using the older FreeBSD `amnezia-kmod` / `amnezia-tools` stack can be migrated by the installer while preserving OPNsense configuration, keys, client instances, servers and peers.

AmneziaWG is a WireGuard-compatible VPN protocol with additional traffic obfuscation mechanisms intended to make WireGuard traffic harder to identify by DPI while retaining the familiar WireGuard network model.

> This is a third-party community plugin. It is not an official OPNsense or AmneziaVPN component.

## Upstream and attribution

This project is based on the client implementation from [MrTheory/os-amneziawg](https://github.com/MrTheory/os-amneziawg).

The upstream project provided the original OPNsense AmneziaWG client integration, including multi-instance client tunnels, AWG 2.0 parameters, configuration import, watchdog/sentinel handling, diagnostics and selective-routing support. `opnsense-awg-plugin` keeps that foundation and extends it with native server mode, server peers, client provisioning, differential lifecycle reconciliation and a hardened installer/update path.

The original copyright notice and BSD 2-Clause License are retained in [LICENSE](LICENSE).

## What the plugin provides

### Client mode

- Multiple independent client tunnels in the shared `awg0`–`awg99` namespace.
- Import of native AmneziaWG `.conf` files with **Parse & Fill**.
- Direct keypair generation from the OPNsense GUI.
- Existing AWG 2.x configuration parameters remain supported for backward compatibility.
- AWG 3.1 parameters: `HeaderProtectionKey`, `ContentPaddingAddition`, ranged timers, ranged `PersistentKeepalive`, `RandomTrailers`, and `DisableCookies`.
- `Table = off`: the plugin does not install policy routes itself. OPNsense Firewall Rules + Gateway remain the source of routing policy.
- Per-instance Start / Stop / Restart.
- Runtime statistics, handshake state and connection diagnostics.
- Private client keys stored outside `config.xml` in root-only files.

### Server mode

- Multiple native AmneziaWG server instances in the same `awg0`–`awg99` namespace as client tunnels.
- Multiple peers per server.
- Server and peer key generation from the GUI.
- Per-peer runtime state: `active`, `inactive`, `never` or `stopped`, with latest handshake and learned endpoint where available.
- Client provisioning directly from a Server Peer:
  - client keypair generation;
  - unique PSK generation;
  - native AmneziaWG `.conf` export;
  - multipart QR generation compatible with AmneziaVPN native AWG import.
- Provisioning settings such as client endpoint, client DNS and client keepalive are kept separate from the server runtime configuration.

### Lifecycle and watchdog

- Persistent canonical managed configs in `/usr/local/etc/amnezia/awgN.conf` with mode `0600`.
- Differential Apply/Reconfigure:
  - unchanged live tunnels remain untouched;
  - changed tunnels are restarted individually;
  - newly enabled tunnels are started;
  - disabled/deleted tunnels are reconciled safely.
- If a changed tunnel fails to restart, the lifecycle engine attempts to restore its previous working canonical config.
- Lifecycle operations are serialized with `flock(2)`.
- The watchdog repairs only the failed managed instance instead of restarting every tunnel.
- Optional per-client active health probes verify the AWG data plane instead of treating an existing interface or sentinel PID as proof of connectivity.
- Optional **Gateway Health Sync** mirrors verified client health into a native OPNsense gateway `Force Down` state, allowing Gateway Groups to fail over without `dpinger`.
- Three consecutive failed probes assert `Force Down`; when the global Watchdog is enabled the affected client tunnel is also restarted with a 10-minute restart cooldown. The next successful probe clears `Force Down`.
- A live `awgN` interface without the expected plugin-owned canonical config is treated as unmanaged and is not taken over blindly.

## Installer and package handling

The v2.1.0 installer uses the project-owned AWG 3.x packages and no longer upgrades AWG from the FreeBSD quarterly repository. At install/update time it resolves the latest GitHub release independently for `opnsense-awg-kmod` and `opnsense-awg-tools`, and requires each package to remain in supported major version 3. The kmod and tools version numbers are intentionally allowed to differ because kernel-only and userspace-only upstream updates are released independently.

Current supported package releases:

| Package | Version |
|---|---|
| `opnsense-awg-kmod` | `3.1.20260906` |
| `opnsense-awg-tools` | `3.1.20260812` |

During an upgrade from v1.0.0 the installer resolves `releases/latest`, downloads each `.pkg` together with its matching `.pkg.sha256` asset, verifies the downloaded checksum and package manifest, creates local rollback packages for the currently installed AWG packages, stops plugin-owned tunnels, removes legacy `amnezia-tools` / `amnezia-kmod`, installs the independently resolved project packages, switches the kernel module from `if_amn` to `if_awg`, runs OPNsense model migrations, validates the resulting configuration, and restores interfaces that were running before the transaction. Existing `config.xml` data, `/usr/local/etc/amnezia` key/config files, `/boot/loader.conf`, and `/boot/loader.conf.local` are backed up before the package transaction.

The installer removes stale `if_amn_load` and `if_awg_load` entries from both loader configuration files and writes the canonical `if_awg_load="YES"` entry to `/boot/loader.conf`. Postflight verifies that no legacy `if_amn_load` entry remains.

A failure after package mutation triggers rollback to the packages, plugin files, loader configuration, OPNsense configuration and key directory captured at the start of the transaction.

## Requirements

- OPNsense 26.7.x or compatible FreeBSD 15.1-based OPNsense release.
- `uname -K` >= `1501000` for the published AWG 3.1 kernel package.
- Network access to GitHub Releases during installation/upgrade.
- `fetch`, `sha256`, `pkg`/`pkg-static`, PHP and the standard OPNsense configd environment.

## Installation

### 1. Download the plugin

For the current repository snapshot on a workstation:

```sh
git clone https://github.com/nvk86/opnsense-awg-plugin.git
cd opnsense-awg-plugin
```

Alternatively, download the repository archive from GitHub and unpack it locally.

### 2. Copy it to OPNsense

```sh
scp -r opnsense-awg-plugin root@<opnsense-ip>:/tmp/opnsense-awg-plugin
```

Then connect to OPNsense:

```sh
ssh root@<opnsense-ip>
sh
cd /tmp/opnsense-awg-plugin
```

`sh` is intentional: the interactive OPNsense/FreeBSD root shell may be `csh`, while the installer is a POSIX shell script.

### 3. Run the installer

```sh
sh install.sh
```

The installer reports the target plugin/AWG versions, verifies the platform and release assets, then performs the package migration as one guarded transaction with rollback on failure.

After installation, refresh the OPNsense GUI and open:

**VPN → AmneziaWG**

The plugin exposes native pages for **General**, **Server**, **Clients** and **Diagnostics**.

## Client configuration

### Add a client tunnel

Open **VPN → AmneziaWG → Clients** and either:

- create a client instance manually; or
- import a native AmneziaWG `.conf` with **Parse & Fill**.

Enable the instance, verify its interface number and AWG parameters, then Apply.

### Assign the AWG interface

For policy routing, assign the generated `awgN` interface in:

**Interfaces → Assignments**

Add the required `awgN`, enable the assigned interface and leave IPv4/IPv6 configuration type at `None` unless your network design explicitly requires something else.

### Create an OPNsense gateway

For a typical client tunnel with local tunnel address `10.8.1.14/32` and remote tunnel address `10.8.1.1`, create a gateway under:

**System → Gateways → Configuration**

Example:

| Field | Value |
|---|---|
| Name | `AWG_GW` |
| Interface | assigned `awgN` interface |
| IP address | remote address inside the AWG tunnel, e.g. `10.8.1.1` |
| Far Gateway | enabled |
| Gateway Monitoring | disable it unless you intentionally configure monitoring |

The public VPS address belongs in the AmneziaWG `Endpoint`; it is **not** the OPNsense policy-routing gateway address.

### Active health monitoring and Gateway Health Sync

Version 2.1.0 can monitor a client with a real ICMP probe sourced from the client's AWG tunnel address.

In **VPN → AmneziaWG → Clients**, edit a client and use:

| Field | Purpose |
|---|---|
| **Health Monitor** | Runs one active data-plane probe every minute. |
| **Health Probe Target** | Optional IPv4 target inside the tunnel. Leave empty to use the native OPNsense gateway configured on the assigned `awgN` interface. |
| **Gateway Health Sync** | Mirrors the debounced health result into the native gateway `Force Down` state. |

The recommended target is the remote AWG tunnel/gateway address because a reply proves the local AWG interface, encrypted transport, remote AWG endpoint and return path are working. The target must answer ICMP.

For **Gateway Health Sync**:

1. assign and enable the `awgN` interface in OPNsense;
2. configure one static IPv4 gateway on that assignment;
3. enable **Health Monitor** and **Gateway Health Sync** on the AWG client;
4. disable native **Gateway Monitoring** for that gateway so the plugin health result is the single source of truth;
5. use the gateway directly or as a member of an OPNsense Gateway Group.

The plugin adopts the existing gateway; it does not replace it. It records the gateway's original `Force Down` value and restores that value when synchronization is disabled, the client is deleted, or the plugin is removed.

A first or second failed probe is treated as transient. On the third consecutive failure the synchronized gateway is forced down and OPNsense's normal routing alarm path rebuilds Gateway Groups/PF policy. The next successful probe clears `Force Down`. If the global **Watchdog** is enabled, the same three-failure threshold also restarts only the affected `awgN` client, with a 10-minute cooldown.

Native `dpinger` remains available when Gateway Health Sync is not used; the two health sources should not control the same gateway at the same time.

### Outbound NAT for Internet breakout

For the normal “send selected LAN/VLAN traffic to the Internet through the VPS” design, create an Outbound NAT rule on the assigned AWG interface:

- source: the local networks that may use the tunnel;
- translation: **Interface address**.

This SNATs LAN/VLAN client addresses to the OPNsense AWG tunnel address before the packet reaches the VPS. A fully routed no-NAT design is possible, but then the VPS/server peer must explicitly route and permit all original LAN/VLAN prefixes in its `AllowedIPs`/routing design.

### Route selected traffic through AWG

Create a normal OPNsense firewall rule on the source LAN/VLAN and choose `AWG_GW` in the rule's **Gateway** field.

The rule must be evaluated before the ordinary default allow rule that would otherwise use the normal WAN routing path.

Because managed client configs use:

```ini
Table = off
```

OPNsense remains responsible for policy routing; `awg-quick` does not install its own default routes.

## Server configuration

Open **VPN → AmneziaWG → Server**.

### Create a server instance

Create an enabled Server instance and configure:

- `awgN` interface number;
- tunnel address/subnet;
- listen port;
- AWG obfuscation parameters;
- public endpoint used when generating client configurations;
- optional client DNS and keepalive provisioning values.

Server and client instances share `awg0`–`awg99`, so the plugin rejects interface-number collisions between them.

### Add Server Peers

Add one or more peers to the server. The plugin can generate client keys and a unique PSK, then use the server settings to produce a complete native client configuration.

For a provisioned peer you can export:

- `.conf` for native AmneziaWG tools;
- QR data for import into AmneziaVPN.

### Firewall and routing for server mode

The plugin manages the AWG interface/configuration. It intentionally does **not** silently create OPNsense firewall or NAT policy for you.

You must configure the surrounding OPNsense policy appropriate for your design, for example:

- a WAN rule allowing the configured UDP listen port to the firewall itself;
- rules on the assigned AWG interface permitting peer traffic to the desired local networks;
- Outbound NAT if VPN peers should use the OPNsense WAN for Internet access;
- routes/firewall policy for site-to-site networks when peer prefixes represent routed remote networks.

This keeps the VPN service lifecycle separate from OPNsense security policy.

## Updating

Run the `install.sh` from the new release directory as root. The v2.1.0 installer is also the migration path from v1.0.0/AWG2; do not manually replace the kernel module or userspace binaries first.

The installer resolves the latest supported AWG 3.x release from each package repository independently on every install/repair run. It downloads each package's matching `.pkg.sha256` asset, verifies hashes and manifests before mutation, and does not require the kmod and tools version strings to match. A kernel-only update therefore requires only a new kmod release, and a tools-only update requires only a new tools release.

## Removing

```sh
./install.sh --uninstall
```

Uninstall stops the service and removes only the OPNsense plugin integration. The AWG packages and `/usr/local/etc/amnezia` are intentionally retained so removing the UI cannot accidentally destroy keys or package state. They can be removed separately after the operator has confirmed they are no longer needed.

## Useful commands

```sh
awg show
configctl amneziawg status
configctl amneziawg version
configctl amneziawg validate
configctl amneziawg restart
configctl amneziawg health <client-uuid>
configctl amneziawg gateway_sync reconcile
```

Logs:

```text
/var/log/amneziawg.log
/var/lib/php/tmp/PHP_errors.log
/var/log/system/latest.log
```

## Important file locations

```text
/usr/local/etc/amnezia/<uuid>.key
    protected client-instance private key

/usr/local/etc/amnezia/server-<uuid>.key
    protected server-instance private key

/usr/local/etc/amnezia/peer-<uuid>.key
    protected provisioned client private key for a Server Peer

/usr/local/etc/amnezia/awg<N>.conf
    persistent plugin-owned canonical managed tunnel config

/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt
    installed plugin version

/var/run/amneziawg.pid
    service sentinel PID

/var/run/amneziawg.lock
    lifecycle serialization lock

/var/log/amneziawg.log
    plugin log
```

## Project layout

```text
plugin/
├── etc/
│   ├── inc/plugins.inc.d/amneziawg.inc
│   ├── newsyslog.conf.d/amneziawg.conf
│   └── rc.syshook.d/start/50-amneziawg
├── mvc/app/
│   ├── controllers/OPNsense/AmneziaWG/
│   ├── models/OPNsense/AmneziaWG/
│   └── views/OPNsense/AmneziaWG/
├── scripts/AmneziaWG/
│   ├── amneziawg-service-control.php
│   ├── amneziawg-health.php
│   ├── amneziawg-gateway-sync.php
│   ├── amneziawg-watchdog.php
│   ├── amneziawg-ifstats.php
└── service/
    └── conf/actions.d/actions_amneziawg.conf
```

The internal `AmneziaWG` MVC namespace and `amneziawg` configd/service identifiers are intentionally retained for compatibility with the existing OPNsense configuration model and upgrade path. The public repository is `opnsense-awg-plugin`.

## License

BSD 2-Clause License. See [LICENSE](LICENSE).

Original upstream source: [MrTheory/os-amneziawg](https://github.com/MrTheory/os-amneziawg).

Additional projects used by or relevant to this plugin:

- [AmneziaVPN](https://github.com/amnezia-vpn) / AmneziaWG
- [OPNsense](https://opnsense.org/)
- [FreeBSD ports/packages](https://www.freebsd.org/)
- [nvk86/opnsense-xray-plugin](https://github.com/nvk86/opnsense-xray-plugin) — companion VLESS + REALITY client plugin for OPNsense; AWG and Xray gateways can be combined using ordinary OPNsense Gateway Groups.
