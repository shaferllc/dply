# Server daemons

The **Daemons** section configures **Supervisor** programs on the server — org-wide or default workers not tied to one site.

## Program list

Each daemon shows:

- **Name** — Supervisor program name
- **Command** — start script
- **Status** — running, stopped, fatal
- **Logs** — stdout/stderr tail links

## Supervisor setup

If Supervisor is not installed, sidebar dots on **Daemons** prompt install from **Manage**.

## Server vs site daemons

Configure site-specific workers under **Site → Daemons** (queue workers, Horizon, Sidekiq, schedulers, and custom binaries). Server **Daemons** covers host-level programs too.

## Actions

Start, stop, and restart queue remote Supervisor commands with streaming output.

## Related sections

- **Site → Daemons** — per-app Supervisor groups
- **Site → Daemons** — Laravel/Redis queue templates and per-program logs
- **Services** — systemd units outside Supervisor
