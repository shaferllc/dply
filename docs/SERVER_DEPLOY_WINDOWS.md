# Deploy windows

The **Deploy windows** section defines when automated and manual deploys may run on this server.

## Window rules

Create schedules such as:

- **Allow deploys** — Mon–Fri 09:00–17:00 org timezone
- **Block deploys** — weekends, holidays, or change freezes

Rules apply to sites on this server when deploy-window enforcement is enabled for the org.

## Manual deploys

Admins may override with an explicit **Deploy anyway** action depending on org policy. Deployers respect windows without override.

## Notifications

Blocked deploy attempts may surface in **Activity** and org notification channels so teams know a push was deferred.

## Pair with maintenance

Use **Maintenance** for visitor-facing downtime and **Deploy windows** for change-management policy — they solve different problems.

## Related sections

- **Site → Deploy** — webhook and auto-deploy settings
- **Activity** — blocked or queued deploy events
- **Fleet → Deploy contracts** — org-wide promote gates (Edge)
