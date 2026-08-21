# ADR: Serverless asset delivery, storage metering, and app buckets

Status: accepted (2026-08-20)

## Context

A Functions Laravel app cannot serve its own `public/build`: DigitalOcean caps
an HTTP response at 1 MB, which a Vite CSS bundle exceeds. `ServerlessAssetPublisher`
already solved that by publishing the build *off* the function — but onto the
`site_assets` disk, which is `driver => local` rooted on the control-plane box
and served through PHP via `serve => true`.

So every customer's JS and CSS was being streamed off dply's own web server, on
dply's CPU and dply's bandwidth, with no CDN in front and no meter behind. That
does not survive traffic, and nothing recovered the cost.

Three further problems surfaced while designing the fix:

1. **Rollback was already broken.** `RollbackServerlessFunctionJob` re-deploys an
   older artifact — "no rebuild, no checkout" — and never republishes assets,
   while `publishBuild()` began by wiping `{prefix}/build`. Vite filenames are
   content-hashed, so a rolled-back app asked for files the newer publish had
   deleted. Latent before this work; a CDN with year-long immutable TTLs would
   have made it harder to diagnose.
2. **DigitalOcean Spaces grants are per bucket, not per prefix.** That decides
   who can be handed which credential, and it is the reason there are two
   buckets below rather than one.
3. **A custom asset domain double-counts its own traffic**, because the
   mechanism that makes one routing rule work for the whole fleet sends the
   request back through the zone.

## Decision

### One bucket, prefix per site, Cloudflare in front

One shared, platform-owned bucket holds every site's build under
`serverless-assets/{label}/`. Cloudflare fronts it; the raw Spaces endpoint is
never published.

Extra buckets are effectively free — a Spaces subscription is a single $5/mo
charge covering many buckets, with the 250 GiB storage and 1,024 GiB transfer
allowances shared across them — so cost did not decide this. Operations did: one
origin, one cache rule, one lifecycle policy, no bucket provisioning in the
deploy hot path. DigitalOcean's per-bucket invoice breakdown would not have
helped either, because with a CDN in front the DO number measures *cache misses*
— it tracks the hit ratio, not customer usage.

Cloudflare rather than the (free) Spaces CDN, because Spaces counts origin→edge
transfer against the same allowance, so its CDN puts all delivery traffic back
on the meter.

### The prefix is the hostname's DNS label

Each site's assets are served from `{label}-assets.{apex}`, and the bucket
prefix is that same label (`ServerlessAssetHost`). That equality lets a single
fleet-wide Cloudflare rewrite route every site — capture the label off the Host
header, prepend the prefix — with no per-site config, no KV, and no Worker on
the hot path.

It also means a hostname is *structurally incapable* of reaching another site's
prefix, so egress cannot be attributed to the wrong site by crafting a URL.
Uniqueness is inherited from DNS, since proxy slugs are already globally unique.

```
http.host matches "^[a-z0-9-]+-[0-9a-f]{8}-assets\.dply-serverless\.cloud$"

concat("/serverless-assets/",
       regex_replace(http.host,
         "^([a-z0-9-]+-[0-9a-f]{8})-assets\.dply-serverless\.cloud$", "${1}"),
       http.request.uri.path)
```

The quantifier is **greedy** on purpose. A site slugged `foo-assets` yields
`foo-assets-a1b2c3d4-assets.{apex}`; greedy matching backtracks to the final
suffix and captures `foo-assets-a1b2c3d4`. A lazy quantifier would capture `foo`
and serve one tenant out of another tenant's prefix. `ServerlessAssetHostTest`
pins this.

DNS needs nothing: `testing_dns.mode` defaults to `wildcard`, so the existing
`*.{apex}` record already answers and Universal SSL covers one label deep.

### Custom domains resolve via per-hostname `custom_origin_server`

A customer's `cdn.acme.com` has no label a static rule can derive a prefix from.
Rather than a rule per hostname (capped by Cloudflare's ruleset limits) or a
Worker, the Cloudflare-for-SaaS custom hostname points its
`custom_origin_server` at *that site's own* asset hostname. The request lands on
a host the fleet-wide rule already matches. Unlimited custom domains, zero extra
rules.

The cost is that the same bytes are logged twice — once on `cdn.acme.com`, once
on the internal hop. See metering below.

A hostname becomes **billable only once Cloudflare reports it active**. A site's
`ASSET_URL` moves to the custom hostname on its *next deploy*, so while
validation is pending the default hostname is still the one serving. Since
billing reads "has a custom hostname" as "the default is an internal hop",
promoting a hostname early would drop real traffic from the meter. Pending
hostnames therefore live in `custom_hostname_details` only, and
`ServerlessAssetDomainProvisioner` promotes them into `custom_hostnames` on
verification.

### Publishing is additive; GC cuts on publishes, not deploys

`publishBuild()` no longer deletes. Content-hashed names make the union of
builds internally consistent, so old and new coexist and rollback resolves.

Every publish re-uploads the whole build directory, so anything still in use had
its mtime refreshed. "Abandoned" is therefore exactly "older than the Nth-most-
recent publish".

Cutting on **publishes** rather than deploys is load-bearing: a rollback records
a successful `SiteDeployment` but republishes nothing, so a deploy-based cutoff
would let a run of rollbacks step past live assets and delete them. The publish
log lives on `meta.serverless.assets.publishes`.

### One job measures and reclaims

`ServerlessAssetGarbageCollector` lists each prefix once, which yields every
object's size *and* mtime — so the sweep that decides what to delete produces
the exact storage figure on the way past. Spaces bills no per-operation fee, so
a daily LIST per site is free.

This makes serverless storage **measured**, not estimated — unlike Edge, where
`EdgeSiteR2StorageEstimator` infers storage from deployment metadata.

### Two meters, generous allowance, priced overage

Spaces bills exactly two things, so there are exactly two meters: stored GiB and
outbound GiB. There is deliberately **no operations meter** — Spaces charges no
per-request fee, so Edge's R2 Class A/B columns have no analogue.
`asset_requests` is recorded but never priced, so abuse is visible and can be
priced later without a backfill.

Rates are the DO cost floor (2c/GiB/mo, 1c/GiB) with the existing
`markup_percent` on top, matching every other rate in that config block.
Allowances (1 GiB stored, 100 GiB egress per function) sit far above a real Vite
bundle, so an honest site never sees a cent. **The meter exists for the tail —
a large binary committed under `public/` — not to sell a storage tier.**

Enforcement splits by failure mode. Storage is checked against the local
directory *before upload* and refuses the deploy with the actual size, because
that is the one moment the user can act. Egress is advisory: a rate-limited
stylesheet breaks a paying customer's site in front of their users, which is
worse than an overage line.

Overage folds into `serverlessUsageSubtotalCents` and rides the existing
`serverless_usage` metered price. No new Stripe product, price, or syncer path.

### Billed egress is a subset of measured egress

Because a custom domain's origin is the site's own asset hostname, the same
bytes appear under both. The site's `ASSET_URL` points at exactly one hostname,
so exactly one is customer-facing:

- custom hostnames attached → bill those
- otherwise → bill the default

Raw per-hostname numbers are stored on the snapshot's
`meta.assets_by_hostname`, so the split stays auditable and a change to this
rule is recomputable from stored data rather than needing re-collection.

Residual undercount: HTML cached before a custom domain was attached still
requests the default hostname and goes unbilled. Small, decaying, and it errs in
the customer's favour.

### Storage aggregates as a level, not a flow

Snapshot rows are one per site per day, so summing stored bytes would multiply
an org's storage by the number of days in the window.
`ServerlessOrganizationUsageReader` divides by distinct days, giving the average
daily total across sites — which is what a per-GiB-**month** rate prices, and it
prorates a site added mid-month correctly.

> Note: `EdgeOrganizationUsageReader` uses `MAX(r2_storage_bytes)`, which takes
> the largest single site-day and undercounts any org with more than one site.
> Not changed here — flagged as pre-existing.

### App buckets are separate, and are what gets injected

The shared asset bucket is written only by the control plane and read only
through the CDN. Its credentials **must never reach a customer's app**: Spaces
grants are per bucket, so a key that can write one site's prefix can read and
overwrite every other site's.

An app that wants its own storage therefore gets its own bucket, with a key
granted to that bucket alone (`ServerlessAppBucketProvisioner`). Isolation here
is free, while sharing would be a cross-tenant breach.

It reaches the app through the channel a provisioned database already uses: the
connection variables are persisted on an encrypted `storage` `SiteBinding` and
merged into the function's managed environment via
`ServerlessEnvironmentPreparer::mergeKeys()`, before `prepare()` writes that env
into the artifact — so the app reads them through Dotenv like any other config.

The disk is named `uploads`, not `s3`: the primary disk stays the operator's to
attach, so dply never silently repoints `FILESYSTEM_DISK`.

## Cutover

Reversible at every step, and no site goes dark:

1. Define the `serverless_assets` disk. Without a driver,
   `ServerlessServiceProvider` keeps aliasing it onto the local store, so this
   is inert until configured.
2. `dply:serverless:backfill-assets` copies existing trees into the bucket,
   renaming `{site_id}` prefixes to `{label}`.
3. Deployed sites keep working untouched — their baked-in `ASSET_URL` points at
   the same-origin `/build` route, and `ServerlessAssetController` reads through
   `Storage::disk()`, so it now serves from the bucket.
4. `DPLY_SERVERLESS_ASSET_CDN_ENABLED=true` moves each site to its CDN hostname
   on its next deploy, when `applyAssetUrl()` rewrites the env.

Reads fall back to the pre-cutover prefix throughout, so a site that has not
been backfilled still serves.

## Consequences

- Assets move **cross-origin**, so CORS is mandatory — Vite emits
  `<script type="module" crossorigin>` and CSS-referenced fonts are CORS-gated.
  `Access-Control-Allow-Origin: *` (they are public files; `*` also avoids
  `Vary: Origin` fragmenting the cache), set both as a Cloudflare response-header
  rule and via a one-time `PutBucketCORS`.
- `ASSET_URL` is baked into each artifact, so old zips carry old asset URLs
  forever. Changing the hostname scheme later strands them.
- The origin remains technically reachable if guessed. Accepted: these are
  public JS/CSS, the origin URL is never published, and a long edge TTL keeps
  origin pulls rare.
