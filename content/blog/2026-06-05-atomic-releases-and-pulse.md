---
title: "Atomic releases: the day deploys became something I'd trust at 2am"
date: 2026-06-05
slug: "2026-06-05-atomic-releases-and-pulse"
summary: "Forty-eight commits. The deploy path moved from in-place mutation to immutable releases with a symlink swap — plus a repo picker rework, a backups dashboard, and the hardening that made it real."
tags: [deploys, atomic-releases, ui, backups, refactor]
type: deep-dive
published: true
---

Forty-eight commits in a day. These are the ones where a dozen half-finished threads all decide to land at once, and the through-line is the one I've wanted for months: deploys that I'd actually trust when I'm half-awake and something is on fire. The headline is **atomic immutable releases with a symlink swap**. The rest of the day — a repo picker rework, a backups dashboard, a Realtime teaser, and a pile of fixes — orbits that, because a real deploy engine drags a lot of supporting machinery into existence with it.

## Why in-place deploys had to die

The old deploy path mutated a checkout in place: pull the new code over the running code, run the build, hope nothing was serving a half-written file in the gap. It works until it doesn't, and when it doesn't, you're debugging a Frankenstein directory that's half old release and half new. There's no clean "undo."

The new model is the one Capistrano taught everyone and I should have adopted from the start. Each deploy lands in its own immutable release directory; going live is just repointing a `current` symlink:

```
/home/dply/example.com/
├── releases/
│   ├── 20260605T140230Z/   # previous
│   └── 20260605T151045Z/   # new — built, ready
├── current -> releases/20260605T151045Z   # the swap is atomic
└── shared/                 # .env, storage, persisted across releases
```

The swap is a single `ln -sfn` — atomic at the filesystem level, so no request ever sees a half-built directory. And rollback stops being a procedure and becomes a fact: point `current` back at the previous release. That's the property you want at 2am. You don't want to *think* at 2am; you want to type one command that cannot be half-applied.

## The fix that proved the design wasn't enough

The design is clean, but the first real deploy taught me that an atomic *web* swap is a lie if you forget the rest of the stack. I had to add a fix to **restart the systemd worker units on the release swap**. The web tier flipped to the new code instantly — and the queue workers kept happily running *yesterday's* code against *today's* database. That's a genuinely dangerous state: a worker deserializing a job whose class shape has changed, or running a migration's old assumptions against the new schema.

So the swap isn't just a symlink anymore; it's a small sequence:

- repoint `current`
- reload the web server
- **bounce the systemd worker units** so they re-exec the new release
- only then call the deploy done

I also had to fix the bare-repo clone to **pull from the server's authenticated remote URL** rather than assuming an already-configured remote. Small, but it's the kind of assumption that works on your dev box and fails on a freshly provisioned one.

## The repo picker, reworked

The other big chunk was the Git repository picker, which I'd outgrown. I **extracted it into a shared trait** (it had been copy-pasted across the create flow and the setup wizard), then made it genuinely usable:

- **Keyboard navigation** in the picker and the global palette — you can drive the whole thing without the mouse.
- A **retry button** on repo commit reads, because the GitHub/GitLab API has bad days.
- A label showing **which linked account answered the read**, so when you have a personal and an org account connected you know which token is talking.
- **Default-branch fallback**: if the configured branch 404s, dply falls back to the repo's default branch and *warns you it did*, instead of silently deploying the wrong thing or just failing.

That last one is the pattern I keep reaching for — degrade gracefully, but never silently. A fallback you don't know about is a future incident.

## Pulse, backups, and the rest of the haul

With deploys solid I built out the surfaces around them. A **backups dashboard** with schedule controls landed, wired to a live **Reverb Pulse** so the state is real-time rather than a stale snapshot. I added **Redis, Database, and Worker server cards to Pulse** so the at-a-glance health view actually covers the stack. Backups itself is marked coming-soon for now — the dashboard is real, the engine behind it is still cooking.

Two more worth noting:

- **HTTP→HTTPS redirect enforcement**, with the deploy commit message **AI-generated** from the change. The changelog now generates titled entries during deploy too — the deploy pipeline is slowly becoming its own narrator.
- A **Realtime coming-soon panel** behind a feature flag, with the Pusher HTTP API proxied to Reverb underneath, so the plumbing is in place before the product face is.

## What bit me

The gremlin of the day was **git identity resolution**. It kept choking on decrypt failures — the encrypted git credentials would fail to decrypt in some contexts and the whole resolver would throw rather than degrade. I **hardened it against decrypt failures** and moved it to a **scoped container binding** so it resolves per-request with the right context instead of leaning on a global. Encryption code is exactly where you don't want a hard throw on the unhappy path; a failed decrypt should narrow your options, not kill the deploy.

The other one was pure Livewire: a crash from a **stale `repo_tab=setup`** sticking around after setup had already ended. The component mounted with a tab state that no longer had any backing data, and Livewire fell over. It's the classic bug that only appears when someone does things in the "wrong" order — which is to say, the order real users always do them in. The fix was to guard the mount against setup state that's outlived its setup.

## What it set up

The tradeoff I'm sitting with: forty-eight commits means a lot of this is "shipped" rather than "lived in." Atomic releases are correct on paper and survived my first deploys, but the only real test is running on them for a week and finding the cases I didn't imagine — a release directory that fills the disk, a worker that doesn't bounce cleanly, a rollback under genuine load.

That's exactly the plan. This is the most "real platform" the deploy path has ever felt, and the next move is to live on it and let it break in ways I can only learn by trusting it.
