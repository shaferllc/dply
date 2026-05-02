# Server providers vs Git (source control)

dply separates **infrastructure** credentials from **source code** access. Keeping them apart avoids mixing DigitalOcean tokens with GitHub OAuth and makes permissions easier to reason about.

## Server providers (infrastructure)

**Where:** Organization **Settings → Server providers** (also labeled **Credentials** in navigation).

**What:** API tokens for providers such as **DigitalOcean**, **Hetzner**, AWS, etc.

**Why:** Create and manage VMs, networking, and (where implemented) DNS automation tied to those accounts.

These tokens are stored encrypted. They are **not** used to clone private Git repositories.

## Git / source control

**Where:** **Profile → Source control** (user-scoped).

**What:** OAuth links to **GitHub**, **GitLab**, or **Bitbucket** (when enabled).

**Why:** Pick repositories for sites, receive webhooks, and drive deployments. One Git identity can serve multiple organizations you belong to.

## Mental model

| Goal | Use |
| --- | --- |
| Provision a server | Server provider credential |
| Connect a repo for deploys | Git provider (profile) |
| DNS with your cloud account | Server provider + zone selection on the site |

## Related

- [Connect a cloud provider](/docs/connect-provider)
- [Source control & deploy flow](/docs/source-control)
