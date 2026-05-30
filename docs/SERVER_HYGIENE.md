# Release hygiene

The **Hygiene** section helps reclaim disk space and remove stale deploy artifacts on the server.

## What it scans

Typical findings include:

- **Old site releases** — atomic deploy directories beyond retention
- **Log rotation** candidates
- **Package cache** bloat
- **Temporary** build or deploy folders

Each item shows estimated space savings and risk level.

## Clean up actions

Select items and queue cleanup jobs. Output streams over SSH. dply avoids deleting the live `current` release or active logs without confirmation.

## Retention alignment

Site-level **Deploy retention** controls how many releases each app keeps. Hygiene complements per-site settings with server-wide sweeps.

## Coming soon preview

When the feature flag is off but preview is on, the page shows a teaser explaining release hygiene without running scans.

## Related sections

- **Deploy** settings on each site — releases to keep
- **Metrics** — disk usage trends
- **Backups** — ensure backups exist before bulk deletes
