---
title: "deploy windows, maintenance mode, and console plumbing"
date: 2026-06-16
slug: "2026-06-16-deploy-windows-and-console"
summary: "Added deploy windows, wired up enabling maintenance mode, and did a big sweep of console and model work after a merge."
tags: [deploys, maintenance, console, models]
published: true
---

Big-footprint day — nearly 900 files touched, a lot of it from a merge landing — but a few real features underneath the churn.

The one I'd been wanting: **deploy windows**. The ability to say "deploys for this site can only happen during these hours" is one of those features that separates a toy from something you'd trust with a real workload. Nobody wants an automated deploy firing at 2pm on a Tuesday when traffic is peaking. Now you can fence it.

Building on yesterday's maintenance page, I wired up actually **enabling maintenance mode** — the page existed, today it got the switch behind it. The two go together: a maintenance page is decoration until you can reliably flip a site into and out of it.

There was also a chunk of **console** work. The commit messages on that are charitably described as "Console stuff," but the file spread — models, jobs, HTTP controllers, Livewire — says it was a proper end-to-end pass rather than a cosmetic one.

## the honest part

My typing today was, uh, aspirational. "depoy windows." "enable maitiance." "mwrged." When the commit messages start losing vowels you can tell I was heads-down and moving fast, which is usually a good sign for output and a bad sign for the git log. I'll let the changelog generator clean up after me.

Most of the weight sat in Models and Jobs, which makes sense — deploy windows and maintenance toggles are state and scheduling problems first, UI problems second. The buttons are the easy part; making sure a windowed deploy actually waits, and a maintenance flag actually holds, is where the real work lives.

Good run this week. Time to let it settle.
