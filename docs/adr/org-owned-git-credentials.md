# ADR: Organization-owned Git credentials

Status: accepted (2026-08-23)

Amends the "user-scoped" statement in `docs/CREDENTIALS_OVERVIEW.md:25` and the
Profile-column placement of "Git source control connections" in
`docs/ORG_ROLES_AND_LIMITS.md:55`.

## Context

Every Git credential in dply belongs to exactly one person. `git_provider_tokens`
and `social_accounts` carry `user_id` and nothing else — no `organization_id`, no
relation, no feature flag, no UI. That is a deliberate design (the overview doc
says so), and for the OAuth identity it is the right one: a `SocialAccount` is
also a login identity, and `social_accounts` carries
`UNIQUE (provider, provider_id)`, so one GitHub account maps to one dply user
platform-wide.

The problem is what deploys do with it. A site is welded to its creator:

- `app/Services/Sites/SiteGitDeployer.php:170` and
  `app/Services/Sites/AtomicSiteDeployer.php:76` resolve the identity as
  `forSite($site, $site->user, $provider)`.
- `GitIdentityResolver` filters every lookup with
  `where('user_id', $user->getKey())` (`forId()`, `forUserProvider()`,
  `candidatesForSite()`).
- `app/Modules/Deploy/Services/DeployRepoPreflight.php:97` walks the same user's
  candidates, marks the dead ones, and on exhaustion reports "All :count stored
  Git identities for :provider were rejected — replace them under Settings →
  Source control".

So:

1. **A personal token expiring stops the organization's deploys.** GitHub
   fine-grained PATs default to 30 days. Only the owning user can replace it.
2. **A member leaving breaks nothing immediately and everything eventually.**
   `site.user_id` still points at the ex-member, deploys keep using their
   personal credential, and no org path exists to inspect or rotate it. If the
   user is deleted, `sites_user_id_foreign ON DELETE CASCADE` takes the sites
   with them.
3. **The remediation is a dead end for everyone else.** `git_auth_failed`
   (`config/product/remediations.php:56`) offers "Update Git credentials" →
   `profile.source-control`, which opens *the reader's* page. If the dead
   credential is someone else's, the button does nothing.

The org already has the right home for this. `ProviderCredential` (cloud tokens)
and `BackupConfiguration` (backup destinations) are both `organization_id`-scoped
and listed on `/organizations/{org}/credentials`. Git credentials — the ones that
actually ship the code — are the only connection missing from that table.

## Decision

1. **Only `GitProviderToken` becomes org-ownable. `SocialAccount` does not.**
   OAuth accounts are sign-in identities with a platform-wide uniqueness
   constraint; making them org-owned would mean an org "owning" a person's
   GitHub login. The machine-user pattern is a PAT (or a deploy key), which is
   what a shared credential should be. Personal OAuth stays exactly as it is.

2. **`git_provider_tokens` gains a nullable `organization_id`, and `user_id`
   becomes nullable.** Exactly one is set, enforced by a check constraint —
   the same shape `ProviderCredential` already uses, minus its tolerance for
   both being null.

3. **Org-owned tokens outrank personal ones in deploy resolution.** Order
   becomes: the pinned identity → healthy **org-owned** tokens for the site's
   organization → the site owner's healthy personal identities → known-bad as a
   last resort. Preferring the org credential is the entire point: it is the one
   that survives a departure. The pin still wins so an operator can force a
   specific identity.

4. **Org tokens are managed on the org Credentials page, not a new page.**
   They become rows in `Credentials\Index::rows()` alongside cloud tokens and
   backup destinations, reusing the PAT form already extracted to
   `resources/views/livewire/settings/partials/source-control/_pat-form.blade.php`.
   `/profile/source-control` keeps personal connections and gains a line
   pointing at the org page.

5. **Role rules mirror `ProviderCredential`.** Org admins and owners create,
   edit, and revoke org-owned tokens; members see that one exists and its health
   but not its value; deployers can use but not manage. Personal tokens stay
   private to their owner — no org-admin visibility, because they are also that
   person's GitHub access outside dply.

6. **`git_auth_failed` routes by ownership.** When the rejected identity is
   org-owned the remediation links to the org Credentials page; when it is
   personal it keeps linking to `profile.source-control`, and its copy says
   whose credential failed.

## Implementation

**Schema** — one migration:

- `git_provider_tokens`: add `organization_id` (nullable `foreignUlid`,
  `constrained()->cascadeOnDelete()`), make `user_id` nullable, add a check
  constraint that exactly one of the two is non-null, and index
  `(organization_id, provider)`.
- No change to `social_accounts`.

**Model** — `app/Models/GitProviderToken.php`:

- `organization()` relation; `organization_id` in `$fillable`.
- `scopeForOrganization($orgId, $provider = null)`, mirroring
  `ProviderCredential::newestForOrganization()`.
- An `owner()` accessor returning the `User` or `Organization`, for UI labels.

**Resolver** — `app/Modules/SourceControl/Services/GitIdentityResolver.php` is
the single choke point and takes the whole behaviour change:

- `forId(User $user, ?string $id)` → also match a token whose
  `organization_id` is one of the user's organizations. Keep the user filter for
  personal rows.
- New `forSiteOrganization(Site $site, string $provider)` returning healthy
  org-owned tokens for `$site->organization_id`.
- `forSite()` and `candidatesForSite()` insert the org tier per decision 3.
- The memo key must include the org, or two users in different orgs share a
  cache entry.

**Deploy paths** — no logic change beyond calling the updated resolver:
`SiteGitDeployer.php:170`, `AtomicSiteDeployer.php:76`,
`DeployRepoPreflight.php:97`.

**Bug to fix in the same pass** — `SiteQuickDeployCommitPoller.php:246` resolves
a pinned account ID with `SocialAccount::find($id) ?? GitProviderToken::find($id)`
and **no ownership filter at all**, so a pinned ID belonging to another user
resolves and is used. That is a cross-tenant credential read today, independent
of this feature. Route it through the resolver.

**UI**:

- `app/Livewire/Credentials/Index.php` — a `gitRows($org)` projection
  (provider, nickname, "Git · N sites", health from `validation_error` /
  `expires_at`), a `storage`-style `git` filter chip, and create/edit/revoke
  actions reusing `_pat-form`.
- `app/Livewire/Settings/SourceControl.php` — unchanged behaviour; the view
  gains a line: "Your organization can own a shared token → Credentials".
- Site repository picker (`ManagesSiteRepositoryConfig`) — list org-owned
  identities in the account dropdown, labelled with the org name so it is
  obvious the credential is shared.

**Policy** — new `GitProviderTokenPolicy`: `view`/`update`/`delete` allow the
owning user for personal rows, and `hasAdminAccess()` for org rows. Register in
`AppServiceProvider` next to `BackupConfigurationPolicy`.

**Docs** — update `docs/CREDENTIALS_OVERVIEW.md:25` and
`docs/ORG_ROLES_AND_LIMITS.md:55`, which both currently state Git connections are
Profile-only.

## Verification

- `php artisan test tests/Feature/SourceControlPatTest.php tests/Feature/SourceControlPageTest.php tests/Feature/CredentialTest.php`
- New tests: an org-owned token deploys a site whose owner has **no** personal
  identity; an org token outranks a healthy personal one; a member of another
  org cannot resolve or see it; the poller no longer resolves a foreign pinned
  ID; a personal token stays invisible to org admins.
- Manual: create an org token on `/organizations/{org}/credentials`, deploy a
  site owned by a different member, then revoke the site owner's personal token
  and confirm the deploy still succeeds.

## Consequences

- A shared credential is a shared blast radius: anyone who can deploy in the org
  can use it against any repo it can reach. That is the trade the machine-user
  pattern makes, and why decision 5 keeps creation admin-only.
- Two tiers of credential means "whose token deployed this?" needs an answer in
  the deploy log; the identity label should be recorded on the deployment row.
- Nothing migrates. Existing personal tokens keep working unchanged; orgs opt in
  by adding one.
