---
title: "Making Edge a real host: routing, SSR adapters, and an Edge-in-front CDN"
date: 2026-05-24
slug: "2026-05-24-edge-routing-and-cdn"
summary: "Twenty-nine commits turning Edge from a static-file uploader into a credible Netlify/Vercel rival — routing rules, multi-framework SSR, env migration, and a Cloudflare CDN layer for VMs."
tags: [edge, cdn, ssr, serverless, cloudflare]
type: deep-dive
published: true
---

Twenty-nine commits, and almost every one is pulling in the same direction: turn **Edge** — dply's first-party static/SSG platform — from "a thing that serves files" into something a person would actually move a production site onto. The day split cleanly into two fronts. One was Edge itself, finally growing the unglamorous features that make a static host usable: routing rules, framework SSR, env migration, runtime bindings. The other was a separate but related idea — putting a **Cloudflare CDN in front of regular VM sites**, so the speed story isn't gated on adopting Edge at all.

What made this a big day wasn't any single marquee feature. It was that a static host is defined by the long tail of small things it *doesn't* make you do by hand, and I spent the day filling in that tail.

## The routing tab nobody enjoys building

Every static host eventually needs redirects, rewrites, and custom headers, and every one of them gets it slightly wrong at first. I gave Edge a **dedicated Routing tab** for exactly these three concerns. The reason it's its own tab and not buried in settings is that these rules are operational, not configurational — you reach for them when a marketing URL changes or a security header audit comes back, and you want them somewhere obvious.

The model is deliberately boring: an ordered list of rules, each one a match plus an action. Conceptually it compiles down to something like this on the edge:

```
# redirects + rewrites + headers, evaluated top-to-bottom
/old-pricing      /pricing            301
/blog/:slug       /articles/:slug     200   # rewrite, not redirect
/*                                          X-Frame-Options: DENY
```

Order matters, first match wins, and rewrites are distinct from redirects — the same trap Netlify's `_redirects` and Vercel's `vercel.json` both expose. Keeping it explicit beats being clever.

## Multi-framework or it doesn't count

Edge already had a Next.js SSR adapter. Today I added **Astro, SvelteKit, and Remix** alongside it. This matters more than it looks: "static host" has quietly come to mean "static host that can also do server-side rendering for the handful of routes that need it," and if you only support one framework's SSR convention you're really a Next.js host wearing a generic label.

Each adapter's job is the same shape — take the framework's build output, figure out which routes are static and which need a server function, and wire the dynamic ones to the edge runtime — but the details differ per framework's output manifest. Doing four of them in a day was only possible because the first one (Next.js) had already forced the abstraction into existence.

## Killing migration friction

Here's the feature I think actually moves the needle: **env var import on hand-off**. When you migrate a site in from Vercel, Netlify, or Cloudflare Pages, dply now pulls your environment variables across automatically instead of making you re-key two dozen secrets by hand.

Migration is where these moves die. Nobody abandons a host because the new one is 5% worse; they abandon the *migration* because re-entering config is tedious and error-prone, and one missing `DATABASE_URL` means a broken deploy on day one. I paired this with **per-site encrypted env-var storage** and a **public env API**, plus `dply.yaml` `build.env_files` merging so file-based and dashboard-based env can coexist. I also wrote **per-provider DNS migration runbooks** into the docs, because the cutover itself — flipping DNS without downtime — is the other place migrations stall.

What shipped on the Edge side:

- Routing tab (redirects / rewrites / headers)
- SSR adapters for Astro, SvelteKit, Remix
- Env var import from Vercel / Netlify / Pages on hand-off
- Encrypted env-var storage + public env API
- KV / R2 / D1 **bindings UI** and runtime env bindings
- Dispatch-script cron schedule API
- Session-authed CSV log download with a filter parser
- 8 SVG hero cards for the template gallery placeholders

## Cleaning up the API before it sprawled

The Edge API was starting to grow the way internal APIs do — each endpoint hand-assembling its JSON payload. I **extracted the payload helpers into proper Eloquent API Resources**, so the wire format lives in one declarative place per model instead of being smeared across controllers. At the same time I gave Edge its **own per-token throttle at 600/min**, separate from the app's general rate limiting, because an automated deploy pipeline hitting the Edge API has a completely different traffic shape than a human clicking around the dashboard, and they shouldn't share a budget.

## Edge in front: a CDN for plain VMs

The second front was a hedge against my own product. Not everyone is going to move to Edge, and they shouldn't have to in order to get a CDN. So I started an **"Edge in front" CDN panel** for regular sites — phase 1 on Cloudflare. It surfaces hit/miss metrics on an hourly snapshot, with per-site cache bypass rules and TTL overrides.

The notable technical decision here: I **swapped the legacy REST analytics for GraphQL**. Cloudflare's older REST analytics endpoint just doesn't give you the granularity — you get coarse rollups when what you actually want is per-path, per-status hit/miss breakdowns over arbitrary time windows. The GraphQL Analytics API lets you ask for exactly the dimensions you want in one query, which is the difference between a dashboard that's decorative and one that tells you whether your cache rules are working. I rounded it out with a dashboard summary, CLI commands, and an audit log for the cache-rule changes.

## What bit me

The most expensive bug of the day was also the dumbest: a **missing `@endif` in the deploy-triggers partial**. Blade's compiler doesn't point at the line where your control structure went unbalanced — it just lets the error cascade, so everything *downstream* of the dangling `@if` renders wrong and the actual culprit is invisible. I lost real time bisecting a template that was, in the end, one keyword short. The lesson I keep re-learning: when a Blade view breaks in a way that makes no semantic sense, suspect an unclosed directive before you suspect your logic.

## What it set up

The honest tradeoff of a day like this is breadth over depth. Twenty-nine commits across routing, SSR, env, bindings, logs, and a whole separate CDN surface means none of them got the obsessive polish a single feature would. The SSR adapters in particular are "works on the happy path" rather than "battle-tested across every framework quirk," and I'll be paying that down as real sites surface the edge cases.

But the shape is right. With routing, multi-framework SSR, frictionless env migration, and a CDN story that doesn't even require adopting Edge, this is the first time I'd genuinely move one of my own sites onto it — which has always been my only real bar for "done."
