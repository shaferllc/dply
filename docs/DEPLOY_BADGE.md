---
title: "Deploy to dply badge"
slug: deploy-badge
category: "Getting started"
order: 50
description: "A copy-paste deploy badge for template authors that sends visitors to a pre-filled Edge create form, with allow-listed params and auth handoff."
---

# Deploy to dply badge

A copy-paste badge for template authors and demo repos. Visitors click the
badge, sign in (or sign up), and land on the Edge create form with their
repo URL pre-filled.

## Markdown snippet

```markdown
[![Deploy to dply](https://dply.dev/images/deploy-to-dply.svg)](https://dply.dev/deploy?repo=OWNER/REPO)
```

Replace `OWNER/REPO` with the GitHub / GitLab / Bitbucket path of your
template. The short-link accepts full clone URLs too — `https://dply.dev/deploy?repo=https://github.com/owner/repo`.

## Pre-filling more than the repo

`/deploy` forwards an allow-listed set of query params to `/edge/create`:

| Param           | Pre-fills                                |
|-----------------|------------------------------------------|
| `repo`          | Repository URL or `owner/name`           |
| `branch`        | Default branch                            |
| `name`          | Suggested app name                       |
| `runtime_mode`  | `static`, `hybrid`, or `ssr`             |
| `build_command` | Build command override                   |
| `output_dir`    | Build output directory                   |

Anything else is dropped. The badge does not need to be re-issued when
you add params — the same SVG works for every link.

## Auth handoff

The destination — `/edge/create` — requires a signed-in dply account. If
the visitor is signed out we send them through login (or registration if
they pick "Start trial"), and Laravel's `intended()` redirect returns
them straight to the pre-filled create form with the query string
preserved. Visitors who are already signed in skip that hop entirely.

## SVG asset

The badge is served as a static SVG at `/images/deploy-to-dply.svg` —
no PHP per request, infinitely cacheable. If you want to mirror it into
your own CDN, that's fine; we don't track badge impressions.
