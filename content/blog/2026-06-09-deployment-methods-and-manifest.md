---
title: "deployment methods, a real manifest, and unsticking the deploy button"
date: 2026-06-09
slug: "2026-06-09-deployment-methods-and-manifest"
summary: "A huge day: a two-axis deployment-methods model, a canonical dply manifest with a deploy gate, and finally killing the stuck deploy button."
tags: [deploys, manifest, ui, refactor]
published: true
---

Sixty-some commits today. When I look back at it, three threads ran in parallel and all of them were things I'd been putting off.

First: **deployment methods**. I built a two-axis model — how you cut over (atomic / maintenance / recreate) as one axis, the rest as the other — and unified everything onto the atomic engine underneath. There's now auto-migration when you switch methods, plus maintenance and recreate cutovers as real options instead of vague intentions. Along the way I fixed the health check and env-apply ordering, which had been subtly wrong.

Second: a **canonical manifest**. This is the one I'm most excited about. The schema now spans four formats plus a healthcheck, there's a `dply:manifest:validate` command for CI, and — the key part — **env declarations in the manifest drive the deploy gate**. A code-shape reconciler reads the repo, surfaces managed read-only rows in the UI, and you get revert/apply/export. Removal is safe: it flags and reverts to the dashboard rather than silently wiping anything. No magic deletions.

Third, the unglamorous hero: the **deploy button**. It had a habit of sticking on a lingering lock and showing "Deploying…" forever. Now failures surface sooner and the button lets go. If you've ever stared at a spinner that will never resolve, you know why this got prioritized.

## the long tail

A pile of smaller stuff rode along:

- AI synthesis now routes through the local `claude` CLI provider, and I had to **hard-timeout + detach stdin** on the pre-push AI generators because they were hanging `git push`. Nothing like a git hook holding your terminal hostage.
- Per-site PHP-FPM pool tuning and a worker env compare, plus fixing app/worker env drift at the root — derived workers now inherit the parent's RESOURCES, not just static env.
- The animated homepage is the default now; the old `welcome.blade.php` is deleted and the footer switcher is gone.
- A date-based version string in the footer, and auto-colored semantic icon badges.

Big day. The kind where the changelog writes itself.
