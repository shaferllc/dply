# Site daemons

The **Daemons** section manages **Supervisor programs** scoped to this site — long-running processes that are not queue workers.

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

- **Queue workers** — Laravel/redis templates
- **Server → Daemons** — host-wide programs
- **Runtime** — PHP/Ruby context for commands
