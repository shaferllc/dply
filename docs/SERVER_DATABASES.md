# Databases workspace

The **Databases** section manages database engines on the server — MySQL, MariaDB, PostgreSQL, SQLite, MongoDB, and ClickHouse.

## Engine tabs

Each engine exposes sub-tabs:

- **Overview** — version, status, data directory
- **Connections** — users, hosts, privileges
- **Backups** — on-server backup jobs
- **Info** — configuration snippets
- **Danger** — uninstall with confirmation modal

**PostgreSQL** adds an **Extensions** tab (PostGIS, pgvector, TimescaleDB).

## Visibility

The sidebar item appears when the server has **mysql** or **postgres** service tags from provisioning. Install engines from empty states.

## Install and uninstall

Engine install/uninstall streams **`db_engine_install`** console actions. Confirm disk space before large imports.

## Site databases

Apps connect via credentials in **Site → Environment**. Create databases and users here, then reference them in the site.

## Related sections

- **Backups** — scheduled dumps to server disk or org S3
- **Site → Laravel / Rails** — framework migration helpers
- **Configuration** — edit `my.cnf` / `postgresql.conf` when allowlisted
