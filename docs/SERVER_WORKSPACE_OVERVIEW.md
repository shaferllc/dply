# Server workspace overview

Each **server** opens a **workspace** with tools grouped like the **site** workspace so operators find parallel capabilities.

## Navigation pattern

Use the sidebar sections to move between areas. Some sections share the same **sub-tab** pattern (for example **Cron**, **Daemons**, **Firewall**, **SSH keys**) with consistent empty states until provisioning and SSH are ready.

## Common sections

| Area | Purpose |
| --- | --- |
| **Overview** | Status, quick context, entry points |
| **Deploy** | Releases and deploy-focused actions for sites on this server |
| **Sites** | Sites hosted on the server |
| **Monitor / Insights** | Health, metrics, findings where enabled |
| **Databases** | Database workspace tools scoped to the server |
| **Cron** | Scheduled commands |
| **Daemons** | Supervised processes / queue workers |
| **Firewall** | Host firewall rules and templates |
| **SSH keys** | Authorized keys sync and access |
| **Settings** | Server-level preferences |

Exact labels follow the live UI; some features require the server to finish provisioning and have working SSH.

## Projects

**Projects** (workspaces) group servers and related resources for delivery workflows—distinct from deployment “project” terminology in older docs.

## Related

- [Create your first server](/docs/create-first-server)
- [Sites, DNS & deploy](/docs/sites-and-deploy)
