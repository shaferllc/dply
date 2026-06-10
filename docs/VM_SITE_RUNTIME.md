---
title: "Site runtime"
slug: vm-site-runtime
category: "Sites & deploys"
order: 250
description: "Select the language runtime and version for a site (PHP, Ruby, Static, or Docker) and configure runtime-specific child tabs."
group: sites
---

# Site runtime

The **Runtime** section selects the **language runtime** and version dply uses for this site.

## Runtime mode

Pick **PHP**, **Ruby**, **Static**, or **Docker** (on VM Docker-capable servers). The choice drives webserver templates and child tabs.

## Version selection

Choose an **enabled** version installed on the server (**Server → PHP** or **Manage**). Installed-but-inactive versions must be enabled on the host first.

## Child tabs

When applicable, nested tabs appear:

- **PHP** — FPM pool settings
- **Ruby** — version and bundler context
- **Static** — root directory and index files

## Docker runtime

Set image, published port, and env for container sites. The **Webserver** proxies to the container port on the VM.

## Related sections

- **System user** — Unix account running the app
- **Laravel / Rails / WordPress** — framework panels when detected
- **Web server config** — vhost `root` and upstream
