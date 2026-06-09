# Database backups

The **Backups** section schedules and restores **database** dumps for engines on this server.

## Backup jobs

Configure:

- **Engine** — MySQL, PostgreSQL, etc.
- **Schedule** — daily/weekly cron
- **Storage** — on-server path (default) or org S3 via presigned upload from the VM
- **Retention** — count or days to keep

Control-plane storage is dev/test only unless explicitly enabled.

## Restore

Pick a backup artifact and queue restore. Confirm in the modal — restore overwrites live data.

## Visibility

Sidebar appears when **mysql** or **postgres** tags exist. Gated behind **`workspace.backups`** with optional coming-soon preview.

## Site shortcut

**Site → Backups** links here with the site context for convenience.

## Related sections

- **Databases → Backups** — per-engine backup tab
- **Hygiene** — prune old dump files
- Org **credentials** — S3 destination for off-server copies
