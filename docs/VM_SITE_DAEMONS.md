# Site daemons

The **Daemons** section manages **Supervisor programs** scoped to this site — queue workers, websocket servers, and other long-running processes supervised by `supervisord`.

## Programs

Define:

- **Command** — e.g. custom worker, websocket server
- **Directory** — usually site `current` release path
- **User** — site system user
- **Auto restart** — Supervisor defaults

## Status

Start, stop, and restart with streaming output. Logs link to **Logs** or Supervisor stdout paths.

## Install Supervisor

If the host lacks Supervisor, install from **Server → Manage** or follow the sidebar setup dot.

## Related sections

- **Services** — systemd units for Node/Rails/Python workers (not PHP/Laravel queues)
- **Queue workers** — Laravel/Redis templates (Horizon, `queue:work`)
- **Server → Daemons** — host-wide programs
- **Runtime** — PHP/Ruby context for commands
