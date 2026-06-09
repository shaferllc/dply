# Shared Host Radar

The **Shared Host Radar** section maps per-site resource use and shared stack dependencies on multi-site VMs — surfacing noisy-neighbor contention before deploys fail.

## When it appears

Shared Host Radar appears in the **Monitor** sidebar when **two or more sites** run on the same server. Single-site hosts show a solo-tenant empty state with a link to **Metrics**.

The workspace is **on by default** (`workspace.shared_host`). Disable with `FEATURE_WORKSPACE_SHARED_HOST=false` if you need to pause it org-wide.

## Site load attribution

Run **Scan load** over SSH to map running processes to each site's repository path:

- **Now** — latest point-in-time CPU and memory per site
- **24h / 7d** — peak and average share rollups from scan history stored on the server record

Each scan appends to attribution history (retained up to ~7 days of samples). Results are marked **stale** after about an hour without a rescan.

## Shared stack map

Built from control-plane **site bindings** (no extra SSH):

- Multiple sites on the same **Redis** or **queue** backend
- Multiple sites bound to the same **database** on this host

Each shared resource lists dependent sites and **restart impact** copy.

## Soft budgets & alerts

Configure per-site **maximum CPU share %** and **memory share %** on the budgets panel. When a site exceeds its budget:

- A **contention timeline** event appears on the radar page
- Subscribed channels receive **`server.shared_host_alerts`** notifications (Slack, email, webhooks, etc.)

Alerts dedupe with a cooldown (default 4 hours). A scheduled job runs every 15 minutes; scans also evaluate budgets immediately.

Subscribe under **Settings → Alerts** or from the **Manage alert subscriptions** link on the budgets panel.

## Contention timeline

Recent events (last seven days) include:

- **Deploy correlated with CPU spike**
- **Noisy neighbor detected** from attribution share
- **Soft budget exceeded**

Suggested actions link to deploys, promote, cron, maintenance, and cost surfaces where enabled.

## Dogfooding checklist

1. Use a server with **two or more sites** and run **Scan load**
2. Switch attribution to **24h** after a few scans to confirm history rollups
3. Set a low budget (e.g. 30%) and verify timeline + notification when breached
4. Subscribe a test channel to **Shared host contention & budget alerts**

## Related sections

- **Health** — server-wide pass/fail probes and capacity headroom
- **Metrics** — time-series CPU, memory, and disk charts
- **Sites** — per-site deploy and runtime settings
- **Maintenance** — suspend lower-priority sites during incidents
