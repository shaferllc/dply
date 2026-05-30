# Configuration editor

The **Configuration** section is a remote file editor for allowlisted server config — nginx, PHP-FPM, systemd units, and stack-specific paths.

## File catalog

The catalog loads via **`wire:init`** so first paint is fast. Each row shows:

- **Path** on the server
- **Role pills** — webserver, PHP, database, etc.
- **One-line hint** from the description resolver

Pick a file to load content with a short-TTL cache (**Cached** badge when served from cache).

## Edit workflow

1. Select a file from the catalog (persisted in `?file=`).
2. Edit in the panel; validation runs locally (log paths ignored).
3. **Save** queues apply with lock + audit trail.
4. Use **side-by-side diff** and **drift detection** against live server state.

## Deep links from Webserver

Opening from **Webserver → Configuration** filters the list to that engine and shows a **Back to {engine}** banner.

## Prerequisites

Requires SSH. Heavy directories are batched; very large files may truncate in the editor.

## Related sections

- **Webserver** — engine switch and reload
- **Nginx Modules** — enable modules via dedicated sub-tab
- **Files** — arbitrary path browse outside the allowlist
