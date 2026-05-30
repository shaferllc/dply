# Shared Host Radar

The **Shared Host Radar** section maps per-site resource use and shared stack dependencies on multi-site VMs — surfacing noisy-neighbor contention before deploys fail.

## When it appears

Shared Host Radar is most useful when **two or more sites** run on the same server. Single-site hosts show a solo-tenant empty state with a link to **Metrics**.

## Site load attribution

Run **Scan load** over SSH to map running processes to each site's repository path:

- **CPU %** attributable to site processes
- **Memory (MB)** from process RSS
- **Share** of total attributable load

Results are cached on the server record and marked **stale** after about an hour. Unattributed CPU and memory cover system daemons and processes outside site paths.

## Shared stack map

Built from control-plane **site bindings** (no extra SSH):

- Multiple sites on the same **Redis** or **queue** backend
- Multiple sites bound to the same **database** on this host

Each shared resource lists dependent sites and **restart impact** copy so you know what breaks together.

## Contention timeline

Recent events (last seven days) include:

- **Deploy correlated with CPU spike** — a site deploy overlapped with host CPU above the configured threshold
- **Noisy neighbor detected** — one site dominates attributable CPU or memory from the latest scan

Suggested actions link to site workspaces, deploy history, or **Promote to standby** when that feature is enabled.

## Coming soon preview

When the feature is gated, the page shows a teaser with sample attribution and shared-resource cards without live analysis.

## Related sections

- **Health** — server-wide pass/fail probes and capacity headroom
- **Metrics** — time-series CPU, memory, and disk charts
- **Sites** — per-site deploy and runtime settings
- **Maintenance** — suspend lower-priority sites during incidents
