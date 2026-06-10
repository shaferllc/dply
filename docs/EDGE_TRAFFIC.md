---
title: "Edge traffic & analytics"
slug: edge-traffic
category: "Edge"
order: 130
description: "CDN-level visibility for managed Edge sites covering requests, bandwidth, cache hit ratio, Worker performance, Core Web Vitals, and access log samples."
group: edge
---

# Edge traffic & analytics

**Traffic & analytics** shows CDN-level visibility for Edge sites on **managed dply Edge** delivery.

> Preview child sites do not include this tab. Use the parent production site.

## BYO Cloudflare delivery

If your site uses **your Cloudflare account** for delivery, charts in dply may be limited. Use the callout link to open **Cloudflare Analytics** in your Cloudflare dashboard for full request and bandwidth data.

## CDN requests and bandwidth

For managed delivery, view time-series charts of:

- **Requests** — edge HTTP requests served
- **Bandwidth** — egress bytes delivered

Adjust the time range where selectors are available. Data reflects Worker-routed Edge hostnames only (not the dply app URL).

## Hybrid cache hit ratio

Hybrid sites show cache performance between static edge assets and origin-fetched HTML. Low hit rates on static paths may mean cache headers need tuning in your build.

## Worker performance

Latency and error metrics from the edge Worker help diagnose slow routes or origin timeouts.

## Core Web Vitals

Real-user **LCP**, **INP**, and **CLS** samples collected from visitor browsers when RUM ingest is enabled. Use these to track front-end performance regressions after deploys.

## Recent access log sample

A truncated list of recent HTTP requests (method, path, status, cache status) for quick debugging. For full log retention, use platform log export features when configured for your org.

## Observability navigation

Use the sub-nav links to jump between **Traffic & analytics**, **Billing & usage**, and **Build & deploy logs** without leaving observability.

## What is not included

This section does **not** show CI build output — see **Build & deploy logs**. It also does not replace Cloudflare’s full dashboard for BYO zones.
