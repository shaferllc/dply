---
title: "Site logs"
slug: vm-site-logs
category: "Sites & deploys"
order: 170
description: "The per-site logs experience with Viewer, Overview, and Sources tabs for tailing vhost/Laravel/Horizon logs over SSH, plus the optional pushed app-log stream."
group: sites
---

# Site logs

The **Logs** page is the server logs experience scoped to one site. It has three
tabs plus an optional app-log stream.

## Tabs

- **Viewer** — live tail of this site's log files on the server, read over SSH.
  Pick a source, filter/grep lines (text or regex, with invert), narrow the time
  range, and turn on **Follow** to stream new lines.
- **Overview** — a summary of what's available for this site and the current
  viewer status.
- **Sources** — the catalog of this site's log sources; click one to open it in
  the Viewer.

## Log sources (scoped to this site)

- **Platform activity** — dply's own activity for the site
- **Access / Error** — this site's vhost logs (nginx/caddy/apache)
- **Laravel** log and **Horizon** log (Laravel sites)

For machine-wide logs (syslog, PHP-FPM, fleet activity), use **Server → Logs** —
there's a one-click link at the bottom of the page.

## App logs (dply Logs)

Separately, you can have your **application** push its own log lines to dply and
read them on this page. That's a push pipeline (not file tailing) you enable per
site. See **[App logs (dply Logs)](/docs/vm-site-app-logs)**.

## CLI alternative

Use **`dply sites:logs <site> --tail`**, or SSH **`tail`** via **Server →
Console** for whole files.

## Related sections

- **Monitor** — uptime, SSL, and response-time checks with alerting
- **Deploy** — full deploy transcript on failure
- **Server → Logs** — system-wide `/var/log` and shipping
