---
title: "edge, CDN, and a very full day"
date: 2026-05-24
slug: "2026-05-24-edge-routing-and-cdn"
summary: "A sprawling Edge and CDN day — routing tab, SSR adapters, env imports from Vercel/Netlify, and an Edge-in-front Cloudflare CDN panel."
tags: [edge, cdn, ssr, serverless]
published: true
---

Twenty-nine commits and they nearly all point at one thing: making **Edge** (the
first-party static/SSG platform) feel like a real competitor to the Netlify /
Vercel / Cloudflare Pages crowd. This was a wide day across a lot of small,
opinionated features.

## edge got serious

A few of the pieces I'm happiest about:

- A **dedicated Routing tab** for redirects, rewrites, and headers — the stuff
  every static host eventually needs and nobody enjoys configuring by hand.
- **SSR adapters for Astro, SvelteKit, and Remix**, sitting alongside the existing
  Next.js one. Multi-framework or it doesn't count.
- **Env var import on hand-off** — when you migrate in from Vercel, Netlify, or
  Pages, dply now pulls your env vars across instead of making you re-key them.
  Migration friction is where these moves die, so this one matters.
- Per-site **encrypted env-var storage**, a public env API, KV/R2/D1 **bindings
  UI**, and `dply.yaml` `build.env_files` merging.

I also extracted the Edge API payloads into proper Eloquent API Resources and gave
it its own per-token throttle (600/min), because the API was starting to sprawl.

## and the CDN

On the Cloud/VM side I started an **"Edge in front" CDN panel** — phase 1 on
Cloudflare. Hit/miss metrics on an hourly snapshot, per-site cache bypass and TTL
overrides, and I **swapped the legacy REST analytics for GraphQL** because the old
analytics endpoint just wasn't giving me the granularity.

The annoying part was the smallest one: a **missing `@endif`** in the
deploy-triggers partial that I'd left dangling. Blade doesn't always tell you
where you broke it, just that everything downstream is now wrong.

Big day. Edge finally feels like something I'd actually move a site onto.
