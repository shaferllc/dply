# Migrating from Vercel to dply Edge

A low-downtime runbook for cutting a production site over from Vercel to dply Edge. The dply Edge create flow (`/edge/import`) handles the build and gives you a preview hostname; this runbook covers everything around it — domain prep, DNS cutover, SSL timing, Vercel-specific feature mapping, and decommissioning.

## 1. Pre-flight checklist

Before you touch anything:

- [ ] Registrar access for the apex domain (or DNS provider where the zone lives)
- [ ] Full list of custom domains attached to the Vercel project (Project → Settings → Domains)
- [ ] Current SSL setup noted (Vercel-managed Let's Encrypt is the default)
- [ ] Production env vars exported from Vercel (`vercel env pull .env.production` from the CLI)
- [ ] `vercel.json` reviewed — rewrites, headers, redirects need hand-translation (see [Gotchas](#provider-specific-gotchas))
- [ ] Note whether the site uses Edge Functions, Edge Middleware, ISR, or on-demand revalidation — these need explicit handling (see below)

## 2. Use the Edge create flow

Open `/edge/import`, pick the connected Git account (or paste the repo URL manually), and let runtime detection fill in `build_command` and `output_dir`. The form covers build config and env vars; it deliberately does **not** touch DNS. Custom domains are attached after the first successful deploy.

For Next.js / Nuxt / SvelteKit projects with SSR, the form auto-selects **hybrid** mode and offers to provision a Cloud origin alongside the Edge site. Static output (`next export`, `nuxt generate`) deploys as pure Edge.

## 3. Deploy and verify on dply

Trigger the first deploy from the Edge workspace **Build** tab (or push to the configured branch with `deploy_on_push` enabled). Wait for the deployment to reach **live** and verify on the temporary hostname:

```
https://{slug}.on-dply.site
```

Walk the critical paths — homepage, dynamic routes, API routes (if hybrid), auth flow, image optimization paths — **before** touching DNS. If anything is broken, fix it while Vercel is still serving production.

## 4. Attach custom domains in dply

For each custom domain, open the Edge workspace → **Domains** tab and click **Attach domain**. dply will show a CNAME target — copy it. The domain starts in **Pending DNS** until verification completes.

| Domain                  | Record type                       | dply CNAME target     |
| ----------------------- | --------------------------------- | --------------------- |
| `www.example.com`       | CNAME                             | `{slug}.on-dply.site` |
| `example.com` (apex)    | ALIAS/ANAME or flattened CNAME    | `{slug}.on-dply.site` |

Apex domains need ALIAS/ANAME support at your DNS host (Cloudflare flattens CNAMEs at apex; Route 53 uses ALIAS).

## 5. DNS cutover with low TTL

A few hours before cutover, **lower the TTL** on the affected records at your DNS host to 60 seconds. Wait for the old TTL to elapse so resolvers respect the new value.

When you're ready:

1. At your DNS provider, change the CNAME for each custom domain from the Vercel target (`cname.vercel-dns.com` or `*.vercel.app`) to the dply CNAME target.
2. For apex records pointing at `76.76.21.21` (Vercel's anycast IP), replace with ALIAS/ANAME → dply CNAME target (or flattened CNAME on Cloudflare).
3. Click **Verify DNS** in the dply Domains tab for each hostname. Status moves Pending → Ready once the CNAME resolves.
4. `dig CNAME www.example.com +short` from a clean network should return your dply target.

## 6. SSL provisioning

dply uses Cloudflare-managed certificates. After **Verify DNS** flips to **Ready**, the certificate typically issues within 5–15 minutes. Confirm with:

```bash
curl -sI https://www.example.com | head -1
openssl s_client -connect www.example.com:443 -servername www.example.com </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer -dates
```

If SSL doesn't issue within 30 minutes, check the Domains tab for a verification error and confirm no leftover Vercel A record is blocking validation.

## 7. Provider-specific gotchas

- **Edge Middleware (`middleware.ts` / `src/middleware.ts`).** dply Edge has its own middleware surface — the Wave D P10a runtime supports request rewriting and header injection at the worker layer. The Next.js `middleware.ts` API is not 1:1; expect to port matchers and rewrite logic by hand. Pure header injection is straightforward; geolocation, A/B, and KV-backed feature flags need rewiring against dply primitives.
- **Edge Functions** (Vercel's `runtime: 'edge'` API routes). For hybrid sites, route them through the Cloud origin instead; for pure Edge, fold them into middleware.
- **ISR / on-demand revalidation has no direct equivalent.** Recommend **hybrid mode** with a Cloud origin: the Edge worker serves cached HTML and falls back to the origin on miss. Use [`EdgeCachePurger`](../../app/Services/Edge/EdgeCachePurger.php) to invalidate on content changes — replace `res.revalidate()` calls with a webhook into dply's cache purge endpoint.
- **`vercel.json` rewrites, redirects, headers** need hand-translation. Static redirects belong in the framework config (Next.js `redirects()`, etc.); dynamic ones belong in middleware. There is no `vercel.json` importer in the create flow.
- **`@vercel/og` image generation** runs at the edge — port to your framework's static OG image generation or a Cloud endpoint.
- **Vercel KV / Postgres / Blob:** swap to a dply Cloud database (Postgres/MySQL/Redis) or an external service. Update env vars before the cutover.
- **Preview deployments:** the Edge create flow provisions GitHub webhooks; PR previews land automatically with `PR #N` shown in the deploy list.

## 8. Rollback

If something breaks after the DNS swap, revert immediately:

1. At your DNS provider, change the CNAME back to `cname.vercel-dns.com` (or restore the original record).
2. With TTL at 60s, propagation is minutes, not hours.
3. Leave the dply site in place — debug, redeploy, then re-cut.

The dply site stays live on `{slug}.on-dply.site` regardless of where the custom domain points.

## 9. Decommission

After 48 hours of stable traffic on dply (check the **Traffic** tab — request volume should match what you saw on Vercel):

1. Raise the TTL on dply records back to 3600s or your normal value.
2. In Vercel: **Project → Settings → Advanced → Delete Project**.
3. Revoke any Vercel access tokens generated for the migration (Account Settings → Tokens).
4. Remove the Vercel GitHub App installation from the repo if no other Vercel projects depend on it.
5. If you used Vercel KV / Postgres / Blob, delete those storage objects from the Vercel dashboard after confirming the dply replacement is fully populated.

Done. Watch the **Traffic** and **Logs** tabs for the first week to catch anything Vercel's edge network was quietly absorbing (image optimization, ISR warmups).
