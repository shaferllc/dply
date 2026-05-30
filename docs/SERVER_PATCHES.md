# Patch advisor

The **Patches** section (sidebar label **Patches**) surfaces pending OS and package updates on the server so you can plan maintenance windows.

## Update inventory

dply probes the host (typically via `apt`) and lists:

- **Security** updates — prioritized
- **Standard** package upgrades
- **Kernel** updates when applicable

Each row shows package name, current version, and available version.

## Apply updates

Patch application runs over SSH as a console action. Expect:

- Streaming output in the workspace
- Possible service restarts (webserver, PHP-FPM)
- Brief traffic impact on active sites

Schedule heavy updates during **Deploy windows** or **Maintenance** mode when possible.

## Reprobe

After updates finish, use **Refresh** to reload the inventory. Stale lists may show until the next probe.

## Related sections

- **Health** — confirm services recovered after patching
- **Hygiene** — old release directories and disk cleanup
- **Maintenance** — suspend visitor traffic during reboots
