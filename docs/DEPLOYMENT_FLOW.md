# Source control & deploy flow

How Git connects to dply, how sites pull code, and what happens when you deploy.

## Two different kinds of “connection”

dply keeps these separate on purpose:

1. **Git (source control)** — OAuth to GitHub, GitLab, or Bitbucket under **Profile → Source control**. Used to pick repositories, receive webhooks, and clone code during deploys.
2. **Server providers** — API tokens for **DigitalOcean**, **Hetzner**, etc., under **Settings → Server providers** (organization‑scoped). Used to create and manage VMs, DNS where supported, and related infrastructure.

You need both when your workflow is “spin up a server here” and “deploy this repo there.”

## Linking a repository to a site

When you configure a site, you choose:

- **Repository** — from your linked Git account (or compatible URL flow your install supports).
- **Branch** — usually `main` or `production`.
- **Deploy settings** — strategy (for example atomic vs simple), commands, and environment.

The control plane stores enough metadata to clone or pull on the remote server and to validate webhook payloads.

## Webhooks

For supported hosts, dply can register or guide you to register a **push webhook** so new commits trigger or queue a deployment without clicking deploy manually—depending on how your organization configures automation.

If webhooks are misconfigured, you can still deploy from the UI or API.

## What happens on deploy (high level)

1. The app records a deployment and assigns it a release path or directory strategy based on your **deploy strategy** (for example zero‑downtime atomic layouts using release dirs and a `current` symlink vs updating a single checkout in place).
2. The server’s deploy user fetches the requested **commit** from Git using credentials or deploy keys your setup provides.
3. Build steps you defined (Composer, npm, artisan, etc.) run in the context of that release.
4. Traffic is switched to the new release (strategy‑dependent), health checks may run, and old releases can be pruned.

Exact commands and paths appear in your site’s deploy configuration and server setup—not duplicated here so this page stays accurate across versions.

## Notifications

Organizations can route deploy notifications through **notification channels** (email, Slack‑compatible webhooks, etc.). Per‑organization toggles exist for deploy‑finish email versus other alerts—check **Organization** settings if your team wants quieter email.

## Related

- [Connect a cloud provider](/docs/connect-provider)
- [Organization roles & plan limits](/docs/org-roles-and-limits)
