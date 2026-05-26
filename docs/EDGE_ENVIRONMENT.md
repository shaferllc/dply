# Edge environment variables

The **Environment** section stores **production-scoped secrets** for your Edge site. Values are encrypted at rest, injected into the build container at deploy time, and exposed as `secret_text` bindings on middleware / SSR workers.

Preview child sites inherit env vars from the parent; the Environment section is hidden on preview workspaces.

## Add or update a variable

1. Open **Environment** in the site sidebar.
2. Enter **Key** (e.g. `API_TOKEN`) and **Value**.
3. Click **Set value**.

Keys are shown in the list; values are **write-only** after save — you cannot read them back from the dashboard.

## Remove a variable

Click **Remove** on a row. The key will be missing from the **next deploy**.

## When vars are applied

Changes take effect on the **next production or preview deploy** that uses this site’s build pipeline. Redeploy from **Deploys** if you need them immediately.

## Related sections

- **Build** — build command and output directory (non-secret config)
- **Bindings** — attach Cloudflare KV, R2, and D1 resources declared in `dply.yaml`
