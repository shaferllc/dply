# ADR: Bundled products (free tracely + Lookout) via dply SSO + entitlement

**Status:** Accepted (dark rollout in progress)
**Date:** 2026-07-01

## Context

Orgs on the most expensive plan **committed for a year** get free access to two
first-party products, **tracely** and **Lookout**. This needs (a) a login
integration so a dply identity works in both products, and (b) an
infrastructure integration so their workspaces are provisioned/suspended in step
with entitlement. Both products are early (no legacy user base). Lookout is
already dply-provisioned in-process (`LookoutProject`, `LookoutProvisioner`);
tracely is an external Laravel app dply does not yet manage.

## Decisions

1. **dply is the OIDC identity provider** (Laravel Passport, auth-code + PKCE).
   tracely + Lookout are statically-registered first-party clients consuming via
   Socialite (already installed). Token claims: `{ sub, email, org_id, org_role,
   bundle_entitled }`. Greenfield auth → pure SSO, no account-linking.
2. **Tenant = Organization.** The org holds the subscription
   (`Organization` is the Cashier billable) and already keys Lookout projects
   (`LookoutProject.organization_id`). One workspace/project **per qualifying
   org**. All org members get access automatically (JIT on first login); dply
   `role` maps to product role. Products are **multi-workspace** — a user in N
   qualifying orgs sees N workspaces.
3. **Entitlement = one predicate:** `Organization::qualifiesForBundledProducts()`
   = a valid subscription carrying the **business-yearly** plan price **or** the
   **Enterprise** price. Business-*monthly* does not qualify — the annual
   commitment funds the giveaway. Single source of truth read by the OIDC claim,
   the provisioning emitter, and the reconcile.
4. **Provisioning is asymmetric behind a shared event.** `BundleTransition`
   (`provisioned/suspended/resumed/deleted`) is emitted by
   `BundleEntitlementSynchronizer`. **Lookout** consumes it **in-process**;
   **tracely** consumes a **signed HTTP webhook**. Same events, two transports.
5. **Runtime enforcement = product-local status, not the token.** Push (events)
   is the fast path; **pull entitlements API + nightly reconcile** is the
   correctness backstop so a dropped webhook self-heals within a day. Tokens stay
   short-lived but are not relied on for revocation.
6. **Lifecycle:** cancel of a prepaid year keeps the bundle **until term end**;
   then **grace → suspend (frozen, reversible) → delete after
   `bundle.retention_days` (default 75)**. Triggered by the predicate flipping,
   not the cancel click.
7. **Rollout:** DARK behind `config('bundle.enabled')` (`BUNDLE_PRODUCTS_ENABLED`),
   matching `LOOKOUT_BILLING_ENABLED` / `SERVER_LOGS_BILLING_ENABLED`.
   **Billing landmine:** bundle-provisioned Lookout projects MUST be stamped
   `source = 'bundle'` and excluded from `LookoutProjectBillingObserver` /
   `OrganizationBillingStateComputer` **before** Lookout billing turns on, or the
   first invoice charges customers for the free perk. Idempotent backfill of
   existing qualifiers via `dply:bundle:reconcile`. Staging before production.

## What's built (this pass — the dark domain core)

- `Organization::qualifiesForBundledProducts()` (predicate).
- `BundleTransition` enum + `BundleEntitlementChanged` event.
- `organization_bundle_entitlements` table + model (persisted baseline).
- `BundleEntitlementSynchronizer` (`sync()` diff-and-emit + `purgeExpired()`).
- Fast path: called from `SyncOrganizationBillingJob`.
- `PropagateBundleEntitlement` listener → `SendTracelyBundleWebhookJob`
  (HMAC-signed, idempotent-by-ULID, retrying) + `LookoutBundleProvisioner`.
- `dply:bundle:reconcile` (backfill + nightly), `dply:bundle:purge` (retention).
- `config/bundle.php` dark gate.

## Deliberately deferred (next passes, in order)

1. **Lookout in-process wiring + `source=bundle` billing exclusion.**
   `LookoutBundleProvisioner` is an inert, documented seam until the billing
   exclusion lands — enabling the flag first must not bill anyone.
2. **Passport install + OIDC endpoints + `bundle_entitled`/org claims;** Socialite
   provider config in tracely + Lookout.
3. **tracely webhook handler + JIT provisioning + workspace switcher** (tracely repo).
4. **Pull entitlements API** (`GET /api/v1/orgs/{org}/entitlements`) + service-token auth.
5. **Schedule** `dply:bundle:reconcile` (nightly) and `dply:bundle:purge` (daily).
