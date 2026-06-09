# Server cron jobs

The **Cron jobs** section (sidebar **Cron jobs**) defines scheduled commands that run on the **server** crontab — not Laravel scheduler entries.

## Job list

Each cron shows:

- **Schedule** — cron expression or friendly preset
- **Command** — shell command as the deploy user
- **User** — typically `dply` or root
- **Last run** status when logged

## Run now

**Run now** queues the job over SSH. Output streams via Reverb with poll fallback; results cache briefly for refresh.

## Server vs site crons

| **Server cron** | **Site cron** |
|-----------------|---------------|
| Host-wide maintenance | App deploy path, `artisan schedule:run` |
| This section | **Site → Cron jobs** |

`dply.yaml` **server_crons** sync on deploy applies server-wide entries.

## Sub-tab pattern

Shares **Cron / Daemons / Firewall / SSH keys** tab chrome and the provisioning empty state.

## Related sections

- **Schedule** — visual calendar of upcoming runs
- **Daemons** — long-lived processes vs one-shot cron
- **Activity** — audit of manual runs
