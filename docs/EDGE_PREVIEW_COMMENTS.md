# Edge preview comments

**Preview comments** let reviewers leave feedback tied to a path on a preview deployment.

## Open the dashboard

From a **preview child site**, open **Preview comments** (dedicated route from preview workspace or fleet). Production sites link here when viewing preview-specific tools.

If the page 404s, confirm you are on a preview deployment, not the parent production site.

## Enable the widget

On the **parent** site → **Previews**, enable **Preview comment widget**. Without this, the dashboard still lists comments but the on-page overlay widget may not appear on preview URLs yet.

## Add a comment

1. Click **Add comment**.
2. Enter **Path** — URL path on the preview site (e.g. `/pricing`).
3. Enter **Body** — feedback text.
4. Save.

Comments appear in the list with author and timestamp.

## Resolve and unresolve

Mark comments **Resolved** when addressed, or **Unresolve** to reopen. Helps triage review threads before merge.

## Delete

Remove spam or outdated comments with **Delete**. Requires appropriate site permissions.

## On-page widget status

A banner may note when the embeddable on-page widget is not yet shipped. Until then, use this dashboard as the source of truth for preview feedback.

## API access

Preview comment storage exposes an HTTP API for widget integrations (used by edge Worker routes). Operators configure widgets via **Previews**; end users interact on the preview URL when enabled.

## Best practices

- Reference specific paths so developers know which page to open
- Resolve threads after fixes land on the preview branch
- Tear down old previews from **Previews** when review is complete
