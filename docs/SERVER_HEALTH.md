# Server health

The **Health** section is a cockpit for host readiness — services, probes, and actionable findings beyond the Overview snapshot.

## Health summary

The top card aggregates:

- **SSH reachability**
- **Webserver** process status
- **Disk and memory** pressure when probed
- **Expected services** from the provision stack

Green/yellow/red indicators highlight what needs attention.

## Service checks

Individual rows may cover:

- Nginx / Caddy / Apache
- PHP-FPM pools
- Database engines (MySQL, PostgreSQL)
- Redis or other cache daemons

Failed checks link to the relevant stack section (**Webserver**, **PHP**, **Databases**).

## When to use Health vs Metrics

| **Health** | **Metrics** |
|------------|-------------|
| Pass/fail service probes | Time-series CPU, memory, disk charts |
| Action-oriented findings | Trend monitoring over hours/days |

## Prerequisites

Requires **ready** server status and working SSH. Hidden on Kubernetes host kinds.

## Related sections

- **Patches** — pending OS/security updates
- **Insights** — recommendations and anomalies
- **Security** — auth.log digest and SSH findings
