# Load balancers

Spread traffic across this server and its peers with managed, health-checked load balancers — provisioned and wired up from the dashboard.

## What this tab shows

The load balancers that currently target **this** server. To create a balancer, or to manage every balancer in the workspace, use **Networking → Load balancers**.

## Health checks

Each balancer probes its backends and only routes to healthy targets, so a failing app server is pulled from rotation until it recovers.

## Provisioning

Balancers are provisioned through your connected provider. Hetzner uses a provider-managed load balancer; other providers are configured on-box (HAProxy-style backends) and kept in sync automatically as you add or remove targets.

## Related sections

- **Networking** — create and manage all load balancers
- **Firewall** — allow balancer health-check traffic
- **Monitoring** — watch backend load after attaching
