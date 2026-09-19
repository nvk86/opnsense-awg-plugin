# opnsense-awg-plugin 2.3.0

Minor release adding native Prometheus monitoring.

## What changed

- Added a read-only Prometheus endpoint at `/api/amneziawg/service/metrics`.
- Exposes service, client/server runtime, cached active-health, watchdog, Gateway Health Sync and handshake state.
- Scrapes do not initiate health probes or mutate tunnel/gateway state.
- Added a dedicated **AmneziaWG: Prometheus metrics** ACL privilege.
- Sensitive configuration such as keys, peer endpoints, health targets and internal UUIDs is not exported as metric labels.
- Installer now invalidates the OPNsense ACL cache after install, rollback and uninstall so newly installed privileges are visible immediately.

## Upgrade

Install normally over 2.2.0. Existing clients, servers, peers, keys, gateways, health settings and policy-routing configuration are preserved.
