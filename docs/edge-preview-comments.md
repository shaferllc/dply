# Edge — preview deploys + reviewer comments

End-to-end review workflow for Edge preview deploys: GitHub Check Runs,
PR summary comment, on-page comment widget, dashboard review surface.

## Status (2026-05-24)

| Piece | Status | Notes |
|---|---|---|
| C1 GitHub Check Run for previews | ✅ shipped | Posted on preview spawn, updated on success/failure. |
| C2 PR summary comment | ✅ shipped | Idempotent — single comment updated in place per PR. |
| C3 On-page widget injection | ✅ shipped | Worker injects an inline self-contained widget IIFE on preview HTML responses when the parent site has `comment_widget.enabled = true`. Floating button → sidebar with comment list + add-comment form, talks to `/api/edge/preview-comments/{site}` with the per-parent widget token in `X-Dply-Preview-Widget`. |
| C4 PreviewComment model + table | ✅ shipped | `edge_preview_comments` table, ULID PK, indexed by site + resolved_at. |
| C5 Dashboard list | ✅ shipped | `App\Livewire\Sites\EdgePreviewComments` at `sites.preview-comments` route — list, add, resolve, delete. |
| C6 Magic-link RBAC | ⏳ not started | Dashboard requires logged-in operator today. Need a tokens/sessions model for non-engineer reviewers. |
| C7 Viewport pinning + screenshots | ⏳ not started | Hangs off the C3 widget — needs anchor selection UX + screenshot capture (headless Chromium). |

## How preview deploys reach GitHub

```
PR opened
  │
  ▼
GithubEdgeWebhookController (extracts head.sha + pr.number)
  │
  ▼
CreateEdgePreviewSite::handle($parent, $branch, $prNumber, $headSha)
  │  • persists head_sha into meta.edge.preview_head_sha
  │  • dispatches BuildEdgeSiteJob
  ├──▶ EdgeGithubCheckRunService::create()       → "in_progress" check
  └──▶ EdgeGithubPullRequestCommenter::upsert("building")  → PR comment

BuildEdgeSiteJob → PublishEdgeDeploymentJob
  │  • on success:
  ├──▶ EdgeGithubCheckRunService::complete("success", $liveUrl)
  └──▶ EdgeGithubPullRequestCommenter::upsert("success", $liveUrl)
  │  • on failure:
  ├──▶ EdgeGithubCheckRunService::complete("failure")
  └──▶ EdgeGithubPullRequestCommenter::upsert("failure")
```

All four service calls are wrapped in try/catch — GitHub flake never
fails a deploy.

## Enabling the on-page widget (C3)

Off by default. Toggle from **Build settings → Preview comment widget**
on the parent site. Enabling generates a widget token (stored in
`meta.edge.comment_widget.token`) and re-publishes the host map so
every preview descended from this parent picks up the widget on the
next deploy (or immediately if the active preview deployment is
re-published).

What the visitor sees on a preview hostname:

- Floating bottom-right "💬 Comments" button.
- Clicking opens a sidebar showing existing comments + a new-comment
  form (optional name + body).
- Submitted comments POST to `/api/edge/preview-comments/{site}` on
  the dply backend with the widget token in `X-Dply-Preview-Widget`.

The widget is a self-contained IIFE inlined into the injected script
tag — no separate bundle to load, no external dependencies. CSS uses
the `dpc-` class prefix to avoid colliding with host page styles.

### REST API (called by the widget)

- `GET /api/edge/preview-comments/{site}` — list comments.
- `POST /api/edge/preview-comments/{site}` — create comment.
- `OPTIONS /api/edge/preview-comments/{site}` — CORS preflight.

Headers: `X-Dply-Preview-Widget: <token>`. CORS allows any origin
matching `*.<testing_domain>` (configured via `DPLY_EDGE_TESTING_DOMAINS`).

## Dashboard

Preview sites get a new route:
`/servers/{server}/sites/{site}/preview-comments`. Currently only
operators with `update` on the site can view it. C6 will lift that
restriction for magic-link-authenticated guests.

## Open questions for C6 / C7

- **Magic-link auth model**: piggyback on the existing API tokens
  table with a `kind=preview_reviewer` flag, or new `preview_invite`
  table with TTL + revocation?
- **Widget UI**: bottom-right toggle + sidebar (Vercel-style) or
  inline anchored comments (Linear-style)?
- **Screenshot capture**: server-side via headless Chromium (heavy)
  or client-side via `html2canvas` (limited fidelity, runs in the
  visitor's browser)?
- **Comment thread mirror**: dply-only, GitHub-mirrored as PR
  comments, or both with a sync direction setting?
