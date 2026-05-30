# Server daemons

The **Daemons** section configures **Supervisor** programs on the server — org-wide or default workers not tied to one site.

## Program list

Each daemon shows:

- **Name** — Supervisor program name
- **Command** — start script
- **Status** — running, stopped, fatal
- **Logs** — stdout/stderr tail links

## Supervisor setup

If Supervisor is not installed, sidebar dots on **Daemons** and **Site → Queue workers** prompt install from **Manage**.

## Server vs site daemons

Configure site-specific workers under **Site → Daemons** or **Queue workers**. This section is for host-level programs (custom agents, org-wide consumers).

## Actions

Start, stop, and restart queue remote Supervisor commands with streaming output.

## Related sections

- **Site → Daemons** — per-app Supervisor groups
- **Site → Queue workers** — Laravel/Redis queue templates
- **Services** — systemd units outside Supervisor
