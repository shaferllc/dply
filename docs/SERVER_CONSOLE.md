# Server console

The **Console** section is a terminal-style SSH interface for quick inspection. Prefer **Run** for saved recipes and long scripts.

## Quick actions

One-click buttons run common checks:

- **uptime** — load and uptime
- **disk** — `df -h` per mount
- **memory** — `free -h`
- **listening ports** — `ss -tulpn`
- **nginx status** — webserver service state

## Autocomplete and help

Press **Tab** for suggestions from:

- Curated command catalog (nginx, PHP, MySQL, etc.)
- Binaries discovered on the host
- Recent shell history

The help sidebar lists commands filtered by installed services.

## Limits

- **60 second** command timeout
- **16KB** output cap per command
- **30** history entries per session
- One command at a time

## Console drawer

A global console drawer is available from any page. It remembers the last server and works without autocomplete — open the full **Console** page for the full experience.

## dply CLI on server

If the **dply** CLI is installed on the host, the console shows a version pill. Otherwise an install banner offers one-click setup.

## Permissions

Same as **Run**: deployers see a read-only interface and cannot execute commands.
