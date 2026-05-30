# Docker workspace

The **Docker** section inspects and maintains Docker on the VM — containers, images, volumes, networks, and compose stacks over SSH.

## Sub-tabs

| Tab | Purpose |
|-----|---------|
| **Overview** | Engine version, disk use, quick stats |
| **Containers** | Running/stopped containers, logs, **Run command** (`docker exec`) |
| **Images** | Local images, pull and prune |
| **Volumes / Networks** | Storage and networking inventory |
| **Compose** | Detected compose projects |
| **Maintenance** | Prune unused resources |

## Install and upgrade

Install or upgrade Docker from **Overview** when missing. Actions stream like other server console jobs.

## VM Docker sites

Sites using **Docker runtime** on a normal VM (not a dedicated Docker host) publish container ports; the **Webserver** reverse-proxies to them.

## Deep links

Use `?tab=` query params to link directly to a sub-tab from notifications or docs.

## Related sections

- **Manage** — Docker CLI via mise when not using the inspector
- **Site → Runtime** — container image and port for one app
- **Services** — non-Docker daemons
