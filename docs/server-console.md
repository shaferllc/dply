# Server Console

The Server Console provides a terminal-style SSH interface for quickly inspecting and interacting with servers.

## Overview

The console is available in two forms:

1. **Console Page** (`/servers/{id}/console`) - Full-featured terminal with autocomplete and help sidebar
2. **Console Drawer** - Embedded console accessible from any page via the global drawer

## Console vs Run Page

| Feature | Console | Run Page |
|---------|---------|----------|
| Purpose | Quick inspection commands | Saved recipes, long-running scripts |
| History | Session-only (30 entries) | Persistent audit logs |
| Timeout | 60 seconds | 300-900 seconds |
| Output | 16KB cap, no streaming | Streaming output |
| Autocomplete | Tab-triggered | None |
| Help Sidebar | Yes | No |

## Console Page Features

### Quick Actions

One-click buttons for common inspection commands:

- `uptime` - Load average and uptime
- `disk` (`df -h`) - Disk usage per mount
- `memory` (`free -h`) - Memory usage
- `who` - Currently logged-in users
- `top processes` - Top 15 processes by CPU
- `listening ports` (`ss -tulpn`) - Listening TCP/UDP ports
- `nginx status` - Nginx service status
- `kernel` (`uname -a`) - Kernel and architecture

### Autocomplete

Press `Tab` to trigger autocomplete with three sources:

1. **Catalog** - Curated commands from service-specific catalogs (nginx, php, mysql, etc.)
2. **Installed** - Server binaries discovered via `compgen -c`
3. **History** - Recent shell commands from `.bash_history` / `.zsh_history`

### Smart Argspecs

Commands with special argument handling:

| Command | Argspec |
|---------|---------|
| `systemctl` | `<verb> <unit>` - verbs and units derived from installed services |
| `service` | `<name> <verb>` - supports both argument orders |
| `journalctl` | `-u <unit>` - suggests units after `-u` flag |
| `tail` / `less` | `<path>` - suggests known log paths |
| `dply` | `<subcommand>` - suggests dply CLI commands |
| `sudo` | `<command>` - suggests systemctl, ufw, nginx, php-fpm |
| `nginx` | `<command>` - suggests `-t`, `-s reload`, etc. |
| `ufw` | `<action>` - suggests status, enable, disable, allow, deny |

### dply CLI

The console detects if the `dply` CLI is installed on the server:

- **Missing**: Shows install banner with "Install dply CLI" button
- **Partial** (binary present but jq or state file missing): Shows repair banner
- **OK**: Shows green pill indicator with installed version

The dply CLI provides convenient subcommands:

```bash
dply status          # Server health summary
dply restart php     # Graceful PHP-FPM restart
dply restart web     # Restart webserver (nginx/apache/caddy)
dply tail nginx      # Follow nginx error log
dply site list       # List managed sites
dply recipe list     # List saved recipes
dply recipe run <name>  # Execute a saved recipe
```

## Console Drawer

The global console drawer is available on any page:

- Automatically selects the route-bound server when on server pages
- Remembers last selected server across page navigation (stored in session)
- Shows server picker when no server in context
- Trimmed UX without autocomplete or help sidebar (use the full Console page for those)

### Server Unavailability Handling

The drawer monitors server status and shows indicators when:

- Server status is not "ready"
- SSH key is missing
- Server becomes unreachable during use

Shows "Unavailable" badge and disables the command input when server is not ready.

## Role Restrictions

| Role | Console Access |
|------|----------------|
| Owner | Full access |
| Admin | Full access |
| Member | Full access |
| Deployer | **Cannot run commands** - sees read-only interface |

Deployers can still see the console interface but attempting to run commands shows an error. This mirrors the Run page restrictions.

## Error Handling

Common errors are classified into user-friendly messages:

| Error | Message |
|-------|---------|
| Connection refused/timed out | "SSH connection failed: Server may be offline or unreachable." |
| Authentication failed | "SSH authentication failed: Check server SSH key configuration." |
| Host key verification failed | "SSH host key verification failed. Server identity may have changed." |
| Timeout | "Command timed out after 60 seconds. Try a simpler command or use the Run page." |
| Permission denied | "Permission denied: The SSH user does not have permission to run this command." |

Concurrent command execution is prevented - only one command can run at a time per console session.

## Catalog Sections

The help sidebar shows commands organized by service:

- **System** - Always shown (uptime, df, ps, ss, who, etc.)
- **Nginx** - Shown when nginx is installed
- **PHP** - Shown when PHP is installed (version-aware)
- **MySQL** - Shown when MySQL/MariaDB is installed
- **PostgreSQL** - Shown when PostgreSQL is installed
- **Redis** - Shown when Redis or Valkey is installed
- **dply CLI** - Shown when dply tag is present

Sections are filtered based on the server's installed services (from `meta.expected_services`).

## Technical Details

### SSH Connection

Uses `SshConnection` class with 60-second timeout. Commands are executed as the configured SSH user (typically `deploy` or `root`).

### Output Caps

- Each history entry: 16KB (truncated with "… (output truncated)" marker)
- Total history: 30 entries
- Command length: 2000 character limit

### Probes

On page load, the console probes the server for:
1. Available binaries (`compgen -c`)
2. Shell history (`~/.bash_history`, `~/.zsh_history`)
3. dply CLI installation status

Probes run asynchronously via `wire:init` after initial page render.

### Audit Logging

Every command execution is audit logged with:
- Command text (truncated to 1000 chars)
- Exit code (if available)
- Status (success, nonzero_exit, failed)
- Duration in milliseconds
- Error message (if any, truncated to 500 chars)

Command output is intentionally NOT logged (may contain secrets).
