# Site repository

The **Repository** section connects Git and defines what dply clones on deploy.

## Git connection

Configure:

- **Provider** — GitHub, GitLab, Bitbucket (OAuth-linked org account)
- **Repository** — owner/name picker or manual URL
- **Branch** — production branch to deploy
- **Deploy key** — read-only key dply uses to clone

## Branch switches

Changing branch affects the **next deploy** only; live code stays until you deploy.

## Monorepo root

Set **Repository root** subdirectory when the app lives in a folder (`apps/web`, etc.).

## Commits view

Some workspaces expose recent commits and manual deploy-from-SHA actions.

## Related sections

- **Deploy** — webhooks and hooks
- **`dply.yaml`** — repo-driven redirects, crons, env declarations
- **Profile → Source control** — OAuth to Git providers
