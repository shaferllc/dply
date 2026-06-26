---
title: "snapshots, system users, and cutting dead providers"
date: 2026-06-08
slug: "2026-06-08-snapshots-and-pruning-providers"
summary: "Shipped a server Snapshots workspace, system-user management, and finally deleted four cloud providers nobody was using."
tags: [servers, snapshots, providers, ui]
published: true
---

Today was equal parts building and deleting, which is my favorite kind of day. The headline is a new **server Snapshots workspace** — images plus DB and cache snapshots in one place — but the thing I'm quietly happiest about is the cleanup.

I dropped GCP, Scaleway, Equinix Metal, and Fly.io as providers. They were half-supported at best, and every one of them was a branch I had to keep alive in my head whenever I touched provisioning. Carrying four "kind of works" integrations is worse than carrying zero. So they're gone, and the provider matrix got a lot more honest.

A few other things landed on the sites side:

- **System user management** — you can actually see and manage the system users on a box now, instead of pretending they don't exist.
- **Tabbed database management** plus per-channel notifications, so the database UI stops being one long scroll.
- A **one-pass reachability probe** for site bindings, and a fix for the resource map connectors that were drawing wrong under CSS zoom (that one bugged me for a while — the SVG edges drifted off the nodes whenever the page was zoomed).

On the plumbing side, the log drain receiver moved from UDP to TLS/TCP, which is the boring-but-correct call for anything carrying logs across the wire. And I caught a config-editor bug where it was reading files over a direct SSH call instead of inline — exactly the kind of render-path SSH I keep telling myself not to do.

## what bit me

The resource-map-under-zoom thing. Connectors that line up perfectly at 100% and then quietly slide off when the browser zoom changes are a special flavor of annoying, because everything *looks* fine in your own window.

Tomorrow: probably more deploy-side work. There's a stuck "Deploying…" button that's been taunting me.
