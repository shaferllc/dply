# Customer-Facing Secrets Vault — Product Design

Status: **DRAFT for review.** No code yet. This is the review the seam comments in
`OrgEnvBundleSource` / `SiteEnvBundleSource` explicitly defer to ("gathering and
re-exporting customer secrets is sensitive and gets its own review with the
customer requirements").

Related: `deploy/SECRETS.md` (operator DR runbook), `deploy/SELF_MANAGE.md`
(self-deploy), `app/Services/Secrets/**` (the engine, already built).

---

## 1. What we're shipping and why

A customer-facing surface to **back up and restore environment secrets** —
versioned, encrypted copies of a site's (later, an org's) `.env`-style secrets,
with one-click restore.

The wedge is concrete and unsexy on purpose: *"I broke my `.env` / rotated a key
wrong / a teammate clobbered a value — restore yesterday's version."* It rides on
top of infrastructure we already operate for our own DR, so the marginal cost is
the UI + two stubbed seams, not a new system.

**Non-goals (v1):** secret *generation*, sharing/teams ACLs beyond existing site
ownership, syncing secrets *into* running servers (that's the existing deploy env
flow), and zero-knowledge guarantees (see §2).

---

## 2. Trust & key model — THE decision

Today `Scope` encrypts every blob to **one platform age recipient**; the matching
private identity is held offline by the operator. Its own docblock: *"all encrypt
to the same platform recipient for now (per-org keypairs are deferred)."*

Consequence: **dply can technically decrypt any customer bundle.** That is
acceptable for an *operator-managed encrypted backup*, and NOT acceptable for a
"we can't read your secrets" zero-knowledge claim.

**Decision for v1: ship on the existing platform recipient, positioned honestly
as managed encrypted backup.** Copy must say "encrypted at rest, restorable by
you" — never "dply cannot read these."

**Deferred upgrade — per-org / BYO key:** generate a per-org keypair (or accept a
customer-supplied recipient). This forces the unavoidable question *who holds the
private identity?*
- Customer holds it → true zero-knowledge, but **restore requires the customer to
  paste their identity back** (lose it = lose the backups; that's the trade).
- dply holds it → marketing-nicer key separation, but still not zero-knowledge.

`Scope::org()` is already threaded through the whole vault and stores are
key-prefixed per scope, so per-org *isolation* already works — only the
*recipient selection* is platform-wide. The upgrade is additive, not a rewrite.

---

## 3. What is in a bundle

A bundle is a portable JSON document of one scope's secrets, gathered by a
`SecretSource`, then age-encrypted and written to the configured stores under
`secret-vault/v1/<scope>/<source>/<utc>-<sha12>.age`.

- **Site bundle** (`SiteEnvBundleSource`, scope `org-<id>`, keyed per site):
  the site's resolved environment secrets — the same set surfaced by
  `ManagesSiteEnvironment`. Start here; it's the simpler seam.
- **Org bundle** (`OrgEnvBundleSource`, scope `org-<id>`): aggregate of all the
  org's sites' env + `WorkspaceVariable` values. Later.

Escrow is **on-change** — `SecretVault::hasVersionWithHash()` already dedupes by
plaintext sha, so an unchanged `.env` doesn't spawn a new version every run.

---

## 4. Restore UX — never blind-overwrite

Restore must **not** silently stomp a live `.env`. Flow:

1. User picks a version from the list (sha, UTC timestamp, which stores hold it).
2. We decrypt to a **staged** bundle and hand it to the existing
   `ManagesSiteEnvironment` editor as a proposed value — same form the user
   already knows, showing a diff against current.
3. User reviews and applies through the normal env-save path (which already
   handles writing + redeploy prompts).

Restore-into-prod stays a deliberate, reviewed user action — mirrors how the
operator side keeps prod restore as break-glass.

---

## 5. Permissions

Gate on existing **site ownership / org membership** policies — a site's secrets
are visible/restorable only to members who can already see that site's env.
No new role primitives in v1. (Operator vault stays separate under
`/admin` + `AuthorizesPlatformAdmin` — do not merge the two surfaces.)

---

## 6. Billing

**v1: free**, as a retention/trust feature. Revisit metering (version count /
retention window / org rollup) only if it drives real cost. Note `secret_vault.db`
already carries a `retention_days` knob we can expose later.

---

## 7. Reuse map — what exists vs. what's net-new

**Already built (reuse as-is):**
- `SecretVault::escrow / listVersions / get / restore`, `VaultBlobRef`
  (carries key, scope, source, createdAt, plaintextSha256, byteLen, stores).
- `AgeEncryptor`, the three `VaultStore`s (object / git / 1Password), config.
- `Scope::org()` plumbing, sha-dedupe, on-change escrow.
- `ManagesSiteEnvironment` (gather + edit + save the env form).

**Net-new (the actual work):**
- Implement `SiteEnvBundleSource::gather()` — currently `throw 'v1 seam'`.
- Queued `EscrowSiteEnvJob` / `RestoreSiteEnvJob` (NEVER inline — they invoke the
  `age` binary + object store; honors the "queue all SSH/long ops" rule).
- Livewire "Versions / Backups" card on the Site → Environment tab.
- Later: `OrgEnvBundleSource::gather()` + an org-settings vault page.

---

## 8. Staged delivery

- **PR1 — engine.** Implement `SiteEnvBundleSource::gather()` + the two queued
  jobs + a command to trigger escrow/restore for a site. Tested, no UI.
- **PR2 — UI.** "Versions / Backups" card on Site → Environment: list versions,
  "Back up now" → job, "Restore this version" → stage into `ManagesSiteEnvironment`
  diff. Policy-gated to site owners.
- **PR3 — org rollup.** `OrgEnvBundleSource` + org-settings "Secrets Vault" page;
  optional billing/retention controls.

PR1+PR2 are independently shippable customer value. PR3 is purely additive.

---

## 9. Open questions for review

1. Trust model: confirm v1 ships on the **platform recipient** (managed backup),
   per-org/BYO key deferred? (§2)
2. Bundle scope of "site env": exactly the `ManagesSiteEnvironment` set, or also
   site-attached binding credentials (DB, object storage, mail)? (§3)
3. Retention: keep all versions, or cap/expire (reuse `retention_days`)? (§6)
4. Does object-store escrow for customer bundles use the **same separate-account
   bucket** as operator DR, or a distinct customer bucket? (isolation/cost)
5. Where does "Back up now" sit relative to deploys — manual only, or auto-escrow
   on every successful env save? (auto = best UX, more writes)
