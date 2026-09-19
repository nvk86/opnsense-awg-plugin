# opnsense-awg-plugin 2.1.1

Hotfix for the active health monitoring introduced in 2.1.0.

## Fixed

- Fixed clients remaining in `health: waiting` after plugin installation or an `awgN` restart when the native OPNsense gateway was Online but its kernel host route had not been restored.
- On a wrong or missing health-target route while the native gateway is not Force Down, health now invokes the normal OPNsense routing alarm for that gateway and rechecks the route immediately.
- Route ownership remains with OPNsense. The plugin does not create a permanent gateway route.
- Forced-down recovery still uses a temporary runtime-only /32 probe route and removes it after recovery.
- Includes the watchdog post-restart cache/cooldown fix from the final 2.1.0 branch.

## Upgrade

Install over 2.1.0 with the normal installer. Existing client/server configuration and native gateways are preserved.
