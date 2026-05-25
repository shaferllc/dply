# Migrating from Cloudflare Pages to dply Edge

A low-downtime runbook for cutting a production site over from Cloudflare Pages to dply Edge. Because both products run on Cloudflare's network, the cutover is mechanically simpler than Netlify or Vercel — but Cloudflare enforces a hostname-uniqueness rule across Pages and Workers that traps unwary operators (see [Gotchas](#provider-specific-gotchas)).

## 1. Pre-flight checklist

Before you touch anything:

- [ ] Cloudflare dashboard access for the account that owns the Pages project + the DNS zone
- [ ] Full list of custom domains attached to the Pages project (Pages → Project → Custom domains)
- [ ] Production env vars exported from Pages (Settings → Environment variables — copy by hand, no CLI export)
- [ ] Note any `_redirects` / `_headers` files in the repo and Pages Functions in `functions/` — these need handling (see [Gotchas](#provider-specific-gotchas))
- [ ] Decide whether you're using **managed `dply_edge`** (dply's Cloudflare account) or **BYO `org_cloudflare`** (your own Cloudflare account, same one Pages uses); see [edge-production-setup.md](../edge-production-setup.md)

## 2. Use the Edge create flow

Open `/edge/create`, pick the connected Git account (or paste the repo URL manually), and let runtime detection fill in `build_command` and `output_dir`. The form covers build config and env vars; it deliberately does **not** touch DNS. Custom domains are attached after the first successful deploy.

If you're moving to BYO mode on the same Cloudflare account, pick **Your Cloudflare account** in the delivery picker and select the bootstrapped credential.

## 3. Deploy and verify on dply

Trigger the first deploy from the Edge workspace **Build** tab (or push to the configured branch with `deploy_on_push` enabled). Wait for the deployment to reach **live** and verify on the temporary hostname:

```
https://{slug}.on-dply.site
```

Walk the critical paths **before** touching DNS. The site stays on Pages serving production while you verify.

## 4. Attach custom domains in dply

For each custom domain, open the Edge workspace → **Domains** tab and click **Attach domain**. dply will show a CNAME target — copy it. The domain starts in **Pending DNS** until verification completes.

| Domain                  | Record type                       | dply CNAME target     |
| ----------------------- | --------------------------------- | --------------------- |
| `www.example.com`       | CNAME (proxied)                   | `{slug}.on-dply.site` |
| `example.com` (apex)    | CNAME flattening (proxied)        | `{slug}.on-dply.site` |

Cloudflare-managed zones support CNAME flattening at the apex, so you do not need ALIAS/ANAME tricks.

## 5. Detach the domain from Pages, then DNS cutover

**This step is the load-bearing one** — see the [Gotchas](#provider-specific-gotchas) note about hostname uniqueness.

1. A few hours before cutover, **lower the TTL** on the affected records to 60 seconds (Cloudflare proxied records ignore TTL on the edge, but lower it anyway in case you flip the proxy off).
2. In **Cloudflare Pages → Project → Custom domains**, click the menu next to the domain and **Remove**. This frees the hostname so it can be attached to the Worker.
3. Within seconds, in the dply Domains tab, click **Verify DNS** for each hostname. Status moves Pending → Ready once the Worker route activates.
4. If you're using BYO mode, the dply CNAME target points at a hostname routed via your Worker on the same zone; no DNS record swap is needed — just the Pages detach.
5. If you're using managed `dply_edge`, update the CNAME at the registrar/DNS provider to the dply target after Pages is detached.

## 6. SSL provisioning

Cloudflare reuses the existing edge certificate for the hostname when you reattach to a Worker on the same zone. For cross-account moves (Pages on one account → managed dply on another), the certificate reissues in 5–15 minutes. Confirm with:

```bash
curl -sI https://www.example.com | head -1
openssl s_client -connect www.example.com:443 -servername www.example.com </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer -dates
```

## 7. Provider-specific gotchas

- **Hostname uniqueness across Cloudflare products.** A hostname cannot be simultaneously attached to a Pages project and a Worker route on the same Cloudflare account. You **must** remove the custom domain from Pages **before** attaching it to dply Edge — otherwise the dply Domains tab will show a verification error and the Worker route silently fails. This is the single most common foot-gun on Pages → Edge moves.
- **Pages Functions (`functions/` directory) translate to dply middleware.** The runtime is similar (both are Cloudflare Workers), but the file-system routing convention from Pages does not auto-map. Port the handlers explicitly. The Wave D P10a middleware surface covers most use cases (request rewriting, header injection, geolocation).
- **`_redirects` and `_headers` files don't auto-translate.** Re-author redirects in the framework or via the SPA fallback toggle in Edge build settings. Commit a [`dply.yaml`](../../app/Services/Deploy/Manifest/DplyManifest.php) for build commands:

  ```yaml
  # dply.yaml
  runtime: static
  build: npm run build
  ```

- **D1 / KV / R2 bindings.** Pages Functions bindings don't carry over. If the migrated site reads/writes D1 or KV, route those calls through a Cloud endpoint or attach the bindings on the dply Worker via the BYO Cloudflare flow.
- **Pages preview deployments:** the Edge create flow provisions GitHub webhooks; PR previews land via the same webhook with `PR #N` shown in the deploy list.
- **Wrangler-deployed Pages.** If the Pages project was deployed via `wrangler pages deploy`, the GitHub integration may not be present — connect the repo through dply's source-control flow before the first deploy.

## 8. Rollback

If something breaks after the cutover, revert immediately:

1. Detach the domain from dply (Edge → Domains → **Remove**) — this frees the hostname.
2. Reattach it in Cloudflare Pages (Project → Custom domains → **Set up a custom domain**).
3. Pages reattaches the existing edge certificate; rollback is typically under a minute on the same account.

The dply site stays live on `{slug}.on-dply.site` regardless of where the custom domain points.

## 9. Decommission

After 48 hours of stable traffic on dply (check the **Traffic** tab):

1. Raise the TTL on dply records back to your normal value.
2. In Cloudflare Pages: **Project → Settings → Delete project**.
3. If you generated a Cloudflare API token specifically for the dply BYO bootstrap, audit its scopes in **My Profile → API Tokens** and rotate or scope it down.
4. Remove the Pages GitHub App installation from the repo if no other Pages projects depend on it.
5. R2 buckets / KV namespaces / D1 databases that were Pages-only can be deleted from the Cloudflare dashboard once the dply replacement is confirmed populated.

Done. Because the underlying network is the same, traffic and latency profiles should be effectively identical post-cutover — any regression points at config drift rather than infra.
