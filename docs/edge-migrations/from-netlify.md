# Migrating from Netlify to dply Edge

A low-downtime runbook for cutting a production site over from Netlify to dply Edge. The dply Edge create flow (`/edge/import`) handles the build and gives you a preview hostname; this runbook covers everything that happens around it — domain prep, DNS cutover, SSL timing, and decommissioning Netlify.

## 1. Pre-flight checklist

Before you touch anything:

- [ ] Registrar access for the apex domain (or DNS provider where the zone lives)
- [ ] Full list of custom domains attached to the Netlify site, including any aliases / `www` redirects
- [ ] Current SSL setup noted (Netlify-managed Let's Encrypt is the default; flag any custom uploaded cert)
- [ ] Production env vars exported from Netlify (Site settings → Environment variables → "Download as .env")
- [ ] Any `_redirects`, `_headers`, `netlify.toml` files in the repo identified — these do **not** auto-translate (see [Gotchas](#provider-specific-gotchas))
- [ ] Note whether the site uses Netlify Functions or Netlify Forms (neither migrates — see [Gotchas](#provider-specific-gotchas))

## 2. Use the Edge create flow

Open `/edge/import`, pick the connected Git account (or paste the repo URL manually), and let runtime detection fill in `build_command` and `output_dir`. The form covers build config and env vars; it deliberately does **not** touch DNS. Custom domains are attached after the first successful deploy in the next step.

If the site uses SSR rather than pure SSG, the form auto-selects **hybrid** mode and offers to provision a Cloud origin alongside the Edge site.

## 3. Deploy and verify on dply

Trigger the first deploy from the Edge workspace **Build** tab (or push to the configured branch with `deploy_on_push` enabled). Wait for the deployment to reach **live** and verify on the temporary hostname:

```
https://{slug}.on-dply.site
```

Click through the critical paths (homepage, a deep article URL, any form that posts, login flow) **before** touching DNS. If anything is broken, fix it on dply while Netlify is still serving production.

## 4. Attach custom domains in dply

For each custom domain, open the Edge workspace → **Domains** tab and click **Attach domain**. dply will show a CNAME target — copy it. The domain starts in **Pending DNS** until verification completes.

| Domain        | Record type | dply CNAME target              |
| ------------- | ----------- | ------------------------------ |
| `www.example.com` | CNAME   | `{slug}.on-dply.site`          |
| `example.com` (apex) | ALIAS/ANAME or flattened CNAME | `{slug}.on-dply.site` |

Apex domains need ALIAS/ANAME support at your DNS host (Cloudflare flattens CNAMEs at apex; Route 53 uses ALIAS; most registrars do not).

## 5. DNS cutover with low TTL

A few hours before cutover, **lower the TTL** on the affected records at Netlify or your DNS host to 60 seconds. Wait for the old TTL to elapse so resolvers respect the new value.

When you're ready:

1. At your DNS provider, change the CNAME for each custom domain from the Netlify target (`apex-loadbalancer.netlify.com`, `*.netlify.app`, or your assigned `*.netlify.app` hostname) to the dply CNAME target.
2. Click **Verify DNS** in the dply Domains tab for each hostname. Status moves Pending → Ready once the CNAME resolves.
3. `dig CNAME www.example.com +short` from a clean network should return your dply target.

## 6. SSL provisioning

dply uses Cloudflare-managed certificates. After **Verify DNS** flips to **Ready**, the certificate typically issues within 5–15 minutes. Confirm with:

```bash
curl -sI https://www.example.com | head -1
openssl s_client -connect www.example.com:443 -servername www.example.com </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer -dates
```

If SSL doesn't issue within 30 minutes, check the Domains tab for a verification error and recheck that the CNAME resolves end-to-end (no proxied A record from a prior provider blocking validation).

## 7. Provider-specific gotchas

- **`_redirects` and `_headers` files don't auto-translate.** The Edge create flow only reads repo-level build config. Commit a [`dply.yaml`](../../app/Services/Deploy/Manifest/DplyManifest.php) for runtime + build commands, and re-author redirects either inline in the framework (Next.js `next.config.js`, Nuxt, SvelteKit) or via the SPA fallback toggle in Edge build settings for client-routed apps.

  ```yaml
  # dply.yaml
  runtime: static
  build: npm run build
  ```

- **Netlify Functions don't migrate.** Move them to dply Cloud (long-running endpoints) or dply Serverless (per-invoke functions) and call from the Edge site.
- **Netlify Forms don't migrate.** Replace with a form endpoint hosted on Cloud/Serverless, or a third-party form service.
- **Branch deploys & deploy previews:** the Edge create flow provisions GitHub webhooks for production pushes. PR previews land via the same webhook with `PR #N` shown in the deploy list.
- **Asset URLs:** Netlify rewrites image URLs through `/.netlify/images`. Update any hardcoded `/.netlify/...` paths before cutover.

## 8. Rollback

If something breaks after the DNS swap, revert immediately:

1. At your DNS provider, change the CNAME back to the Netlify target.
2. Because TTL is 60s, propagation is minutes, not hours.
3. Leave the dply site in place — debug, redeploy, then re-cut.

The dply site stays live on `{slug}.on-dply.site` regardless of where the custom domain points, so you can keep iterating against the temp hostname.

## 9. Decommission

After 48 hours of stable traffic on dply (check the **Traffic** tab — request volume should match what you saw on Netlify):

1. Raise the TTL on dply records back to 3600s or your normal value.
2. In Netlify: **Site settings → General → Delete this site**.
3. Revoke any Netlify Personal Access Tokens generated for the migration (User settings → Applications).
4. Remove the Netlify deploy key from the GitHub repo if one was added.

Done. Keep an eye on the dply **Traffic** and **Logs** tabs for the first week to catch anything the Netlify logs were quietly handling.
