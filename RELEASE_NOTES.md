# opnsense-awg-plugin 2.3.1

Maintenance release improving per-client health monitoring reliability and preventing unnecessary AWG restarts during temporary network failures.

## What changed

- **More resilient ICMP health checks:** Allow up to two source-bound ICMP attempts per scheduled cycle, stopping after the first reply. Checks remain tied to the configured AWG tunnel and its remote gateway.
- **Safer watchdog:** Restart an affected client only after three consecutive local runtime failures; unanswered ICMP probes alone no longer trigger a restart.
- **Gateway Health Sync:** Preserve the three-cycle failed-probe debounce for native gateway `Force Down`, with recovery after a successful probe.
- **Read-only manual Test Health:** GUI checks do not change production health counters, cache, gateway `Force Down` or runtime routes.
- **Concurrent health-check protection:** Serialize scheduled probes per client with close-on-exec locks so subprocesses do not retain them.
- **Automated tests:** Added coverage for ICMP probing, failure classification and watchdog restart decisions.

## Upgrade

Run `sh install.sh` to upgrade from **2.3.0**. The installer preserves existing configuration, keys, clients, servers, peers and gateways and restores previously running tunnels. It resolves and reinstalls the latest supported AWG 3.x kernel module and tools packages, stopping and restarting plugin-managed AWG interfaces during the transaction.
