# Run commands

The **Run** section executes saved recipes and ad-hoc shell commands on the server over SSH. Use it for scripts, maintenance, and marketplace imports — not quick one-liners (see **Console**).

## Saved commands

**Recipes** are org-wide saved commands you can run on this server. Each run:

- Streams output live in the workspace
- Writes an audit log entry
- Supports longer timeouts (minutes, not seconds)

Import additional recipes from **Scripts** / **Marketplace** at org level, then run them here.

## Ad-hoc shell

Run a one-off command without saving it. Output streams to the panel; long jobs may take several minutes.

## Console action banner

Queued install/upgrade actions (PHP, packages, webserver switch) also stream through the same console-action pattern — watch the banner for progress and exit code.

## Run vs Console

| Use **Run** | Use **Console** |
|-------------|-----------------|
| Saved recipes and scripts | Quick inspection (`uptime`, `df`, `ss`) |
| Long-running output | Tab autocomplete and help sidebar |
| Persistent audit history | 60s timeout, session history only |

## Permissions

**Deployer** role members cannot execute commands. Owners, admins, and members have full access.

## Prerequisites

Server must be **ready** with a valid SSH key. If the page shows the provisioning empty state, finish setup on **Overview** first.
