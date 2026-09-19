# opnsense-awg-plugin 2.1.0

Feature release adding active client health monitoring and native OPNsense Gateway Health Sync.

## What changed

- Per-client **Health Monitor** runs an active ICMP probe through the AWG data plane every minute.
- **Health Probe Target** is optional. When left empty, the plugin probes the static IPv4 gateway configured on the assigned AWG interface.
- **Gateway Health Sync** adopts that native gateway and mirrors debounced health into its `Force Down` state.
- The first two consecutive failures are treated as transient. The third failure forces the gateway down; the next successful probe brings it back.
- OPNsense's routing alarm path is invoked whenever `Force Down` changes, so normal Gateway Groups and PF policy are rebuilt.
- The existing gateway remains user-owned. Its original `Force Down` value is restored when sync is disabled, the client is deleted, or the plugin is uninstalled.
- Native gateway monitoring must be disabled while plugin Gateway Health Sync is enabled.
- On first adoption after moving a gateway away from `dpinger`, the plugin performs a one-time OPNsense Gateway Watcher cache reconciliation so stale monitor state cannot mask plugin-driven `Force Down` during Gateway Group/PF regeneration.
- The global Watchdog can additionally restart only the unhealthy AWG client after the same three-failure threshold. Restart cooldown is 10 minutes.
- Health monitoring and gateway synchronization continue to operate even when automatic Watchdog restart is disabled.
- The General page now has an aggregate **Health** badge. It reports the worst monitored client state, including `offline 1/3` and `offline 2/3` during the debounce window; detailed per-client health remains in Diagnostics.
- Diagnostics now includes active health, probe target, latency, consecutive failures, native gateway status, `Force Down`, and loaded PF `route-to` state.
- Added an on-demand **Test Health** action.

## Upgrade

Existing 2.0.x installations upgrade with health monitoring disabled by default, so routing behavior is unchanged until the new per-client options are enabled.

For a client that should participate in Gateway Groups:

1. Assign and enable its `awgN` interface.
2. Configure one static IPv4 OPNsense gateway on that assignment.
3. Disable native Gateway Monitoring on that gateway.
4. Enable **Health Monitor**.
5. Leave **Health Probe Target** empty to probe the gateway itself, or set another ICMP-reachable IPv4 address that is routed through the AWG tunnel.
6. Enable **Gateway Health Sync**.
7. Verify **VPN → AmneziaWG → Diagnostics**.

Native `dpinger` can still be used when Gateway Health Sync is disabled.

## Related project

For mixed transport Gateway Groups, [nvk86/opnsense-xray-plugin](https://github.com/nvk86/opnsense-xray-plugin) provides managed VLESS + REALITY client gateways for OPNsense.
