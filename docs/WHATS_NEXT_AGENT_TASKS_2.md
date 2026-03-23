# What’s Next – Agent Task Briefs (Round 2)

Five self-contained tasks for five different agents. Paths are relative to project root (dply). Share **only one section** per agent.

**Reference:** `docs/WHATS_NEXT.md` (roadmap), `docs/WHATS_NEXT_AGENT_TASKS.md` (completed agents A–H).

---

## Agent I: Add one additional cloud provider (Linode, Vultr, or Hetzner)

**Goal:** Add support for one new cloud provider so users can provision servers there as well as DigitalOcean and “custom”. Implement the full flow: credential, provision job, poll for IP, set server ready, and destroy instance on server delete.

**Context:**
- Current providers: `digitalocean` and `custom`. `ProviderCredential` has `provider` (string) and encrypted `credentials` (e.g. `api_token`). `Server` has `provider`, `provider_id`, `provider_credential_id`, `ssh_user`, etc.
- DigitalOcean flow: `ProviderCredentialController` (store validates DO token), `ProvisionDigitalOceanDropletJob`, `PollDropletIpJob`, `ServerController::destroy()` calls `DigitalOceanService::destroyDroplet()` for DO servers. See `app/Services/DigitalOceanService.php`, `app/Jobs/ProvisionDigitalOceanDropletJob.php`, `app/Jobs/PollDropletIpJob.php`.
- Credentials UI is in `app/Livewire/Credentials/Index.php` and related views; server create flow picks a credential (provider) and region/size/image.

**Tasks:**
1. **Pick one provider:** Linode, Vultr, or Hetzner. Add a constant on `Server` (e.g. `PROVIDER_LINODE`) and support it in credential and server create flows.
2. **Service class:** Create `app/Services/{Provider}Service.php` (e.g. `LinodeService`) that takes a `ProviderCredential`, authenticates with the provider API, and exposes at least: `createInstance(...)` (returns id), get instance by id, get public IP from instance, `destroyInstance(int $id)`.
3. **Credential storage:** In `ProviderCredentialController` (or provider-specific validation), accept and validate the new provider’s API token; store in `credentials` with a key the service expects (e.g. `api_token`). Add credential form option for the new provider in the credentials UI (e.g. “Linode” alongside “DigitalOcean”).
4. **Provision job:** Create `Provision{Provider}Job` that creates the instance via the service, updates the server with `provider_id`, `status`, `ssh_private_key` (if the provider returns one or you generate and inject), and `ssh_user` (e.g. `root`), then dispatches a poll job. Follow the same pattern as `ProvisionDigitalOceanDropletJob` and `PollDropletIpJob` (poll until IP is available, then set `ip_address`, `status = ready`; optionally trigger setup script if present).
5. **Poll job:** Either extend the existing poll job to handle the new provider or add a `Poll{Provider}IpJob` that polls the provider API for the instance’s public IP and updates the server when ready.
6. **Destroy on delete:** In `ServerController::destroy()`, when `provider` is the new provider and `provider_id` is set, call the new service’s destroy method (with the server’s `providerCredential`) before deleting the server record. Catch and log errors; still delete the record.
7. **Server create:** Ensure the server create flow (Livewire and/or controller) allows choosing the new provider’s credentials and passes the correct `provider` when creating the server; dispatch the new provision job instead of the DO one when the selected credential is for the new provider.

**Acceptance:** A user can add a credential for the chosen provider, create a server with it, see it become “ready” with an IP, and delete the server so the cloud instance is destroyed and the row removed. No regression for DigitalOcean or custom servers.

---

## Agent J: API for CI/CD (tokens, list servers, trigger deploy)

**Goal:** Provide an API so CI/CD (e.g. GitHub Actions, GitLab CI) can list servers and trigger a deploy without storing SSH keys in CI. Use API tokens for authentication and simple rate limiting.

**Context:**
- App uses Laravel Breeze (web + Livewire). There is no API or Sanctum yet. Organizations have servers; current user has a “current” organization (session). Deploy is triggered via `ServerController::deploy()` for a server; run-command exists for arbitrary commands.
- Decide scope: token per user, or per organization. Per-organization tokens are often easier for CI (one token per org, team can rotate). Either way, tokens must be tied to an organization for listing servers and deploying.

**Tasks:**
1. **Token storage:** Add a migration for API tokens. Options: (a) Laravel Sanctum `personal_access_tokens` (install Sanctum, use tokenable type = User or create an Organization model token), or (b) a custom `api_tokens` table with `user_id`, `organization_id`, `name`, `token` (hashed), `last_used_at`, `abilities` (optional JSON for scopes). Store a plaintext token only once at creation (show to user); verify with hash.
2. **Create/revoke tokens:** In profile or organization settings, add “API tokens”: create token (name + optional expiry), show secret once; list tokens (masked, last used); revoke. Restrict to org admins if tokens are org-scoped.
3. **API routes:** Under a prefix (e.g. `api/v1`), add:
   - `GET /servers` – list servers for the token’s organization (id, name, status, deploy_command, etc.).
   - `POST /servers/{server}/deploy` – trigger deploy for that server (same logic as `ServerController::deploy()`). Return job id or “started” and optionally poll or webhook for result.
   - Optional: `POST /servers/{server}/run-command` with body `{ "command": "..." }` for arbitrary commands (scope with ability if you add scopes).
4. **Authentication:** Middleware that accepts `Authorization: Bearer <token>` and optionally `X-API-Key: <token>`. Resolve user and/or organization from the token and attach to request so endpoints can use `auth()->user()` and current org.
5. **Authorization:** Ensure the token’s organization matches the server’s organization for deploy and run-command; return 403 otherwise.
6. **Rate limiting:** Apply a throttle to API routes (e.g. 60 requests per minute per token). Use Laravel’s `throttle` middleware with a named limiter.
7. **Docs:** Add a short section in `docs/` or in-app (e.g. “API” in profile/org) describing the base URL, authentication header, and the two (or three) endpoints with example `curl` or a link to a Postman-style example.

**Acceptance:** A user can create an API token, call `GET /api/v1/servers` to list servers and `POST /api/v1/servers/{id}/deploy` to trigger a deploy from CI. Unauthorized and wrong-org requests return 401/403. Rate limit applies.

---

## Agent K: Enforce and clarify email verification

**Goal:** Ensure that creating servers and organizations (and other sensitive actions) require a verified email, and improve UX when the user is not verified (clear message, redirect to verification).

**Context:**
- Laravel Breeze is used; `User` has `email_verified_at`. Routes under `Route::middleware(['auth', 'verified'])` already require verification (see `routes/web.php`). So dashboard, servers, orgs, credentials, profile, and two-factor are already behind `verified`.
- What may be missing: (1) Explicit check in `ServerController::store()` and `OrganizationController::store()` that the user’s email is verified (redundant if middleware is applied but makes intent clear and returns a clear message). (2) When an unverified user hits the app (e.g. bookmarked URL), they get a 403 from `EnsureEmailIsVerified`; optionally show a friendly “Verify your email” page or redirect to verification notice. (3) Document that email verification is required for all app usage.

**Tasks:**
1. **Confirm middleware:** Verify that every route that creates or modifies servers, organizations, credentials, billing, or team membership is inside the `auth, verified` group. If any sensitive route is outside, move it in or add `verified` to its middleware.
2. **Optional explicit checks:** In `ServerController::store()` and `OrganizationController::store()`, add an explicit `if (! $request->user()->hasVerifiedEmail()) { return redirect()->route('verification.notice')->with('error', ...); }` (or equivalent) so the error message is consistent. Ensure `verification.notice` exists (Breeze usually provides it).
3. **UX for 403:** If the default 403 for unverified users is a generic “Your email address is not verified”, ensure the user can get to the verification email flow (e.g. link to “Resend verification email” on that response or a dedicated “Verify email” page). Optionally redirect unverified users who hit the dashboard to a “Please verify your email” view with a resend link.
4. **Documentation:** In `docs/WHATS_NEXT.md` or a new `docs/VERIFICATION.md`, state that email verification is required for all authenticated app use (creating servers, orgs, etc.) and how resend/verify works.

**Acceptance:** No sensitive action is possible without a verified email. Unverified users see a clear message and can resend the verification email. Docs describe the requirement.

---

## Agent L: Session management (list and revoke sessions)

**Goal:** Let users see their active sessions (device/browser, IP, last activity) and revoke other sessions from the profile or settings page. Improves security and control after a lost device or suspicious activity.

**Context:**
- Session driver is `database` (see `config/session.php`). Laravel stores sessions in the `sessions` table with `id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`. The current request’s session id is available via `session()->getId()`.
- No session management UI exists yet. Profile is under `Route::middleware(['auth', 'verified'])` at `/profile` with edit/update/destroy.

**Tasks:**
1. **Sessions table:** If the app uses the default Laravel session table, it has `user_id` and `last_activity`. If not, ensure there is a way to list sessions for the current user (e.g. query by `user_id`). Optionally decode `user_agent` for a human-readable “Chrome on Mac” style label (use a small package or a simple substring).
2. **List sessions:** Add a “Sessions” or “Active sessions” section on the profile page (or a subpage like `/profile/sessions`). List each session with: last activity time, IP address, user agent (or device/browser summary). Mark the current session (e.g. “This device”) so the user doesn’t revoke it by mistake.
3. **Revoke other sessions:** For each session that is not the current one, show a “Revoke” or “Log out this device” button. Revoking means deleting that session row (or calling the same logic Laravel uses to invalidate a session). After revoking, the other device will get logged out on next request.
4. **Security:** Only allow users to list and revoke their own sessions (filter by `auth()->id()`). Do not expose session IDs or payload to the client; only enough to display and to revoke by id.
5. **Optional:** “Revoke all other sessions” button to delete every session for the user except the current one. Useful after a password change or 2FA enable.

**Acceptance:** User can open profile (or profile/sessions), see a list of active sessions with last activity and device info, and revoke any other session. Current session is clearly indicated and cannot be accidentally revoked (or revoking “current” is disabled). Optionally “Revoke all other sessions” works.

---

## Agent M: Docs and onboarding (first-time guides)

**Goal:** Add short, discoverable docs and onboarding so new users know how to “Create your first server” and “Connect DigitalOcean” (or another provider). Link from the dashboard and from first-time empty states.

**Context:**
- The app has a dashboard (`/dashboard`), server list, server create (with provider/credential, region, size, setup script, etc.), and credentials page. There is no docs section or in-app guide yet. `docs/` in the repo has WHATS_NEXT and agent briefs, not end-user docs.

**Tasks:**
1. **Content:** Write two short guides (can live as Markdown in `docs/` or as in-app views):
   - **Connect a cloud provider:** How to get an API token (e.g. DigitalOcean: Account → API → Generate), add it in dply (Credentials → Add credential), and name it. One provider is enough; mention “other providers” if the app supports more.
   - **Create your first server:** Choose provider/credential, name, region, size, optional setup script; submit; what “pending” and “ready” mean; link to server detail and “Run command” or “Deploy”.
2. **Where to show:** (a) Add a “Docs” or “Help” link in the main layout (e.g. nav or footer) that goes to a docs index. (b) On the dashboard, when the user has no servers yet, show an empty state with a short message and a link like “Create your first server” that goes to server create, and “New? Read the guide” (or “Connect DigitalOcean first”) linking to the connect-provider guide. (c) On the server create page, if the user has no credentials yet, show a clear “Add a credential first” and link to credentials and the connect-provider guide.
3. **Docs index:** A simple docs index page (route + view) that lists the two guides (and optionally “API” if Agent J added API docs). You can render Markdown with a simple package or plain HTML views.
4. **Format:** Prefer simple, scannable content (headings, short steps, one link per step). No need for a full docs site; a single page per guide or a single scrollable page with both is fine.

**Acceptance:** A new user can find “Connect DigitalOcean” and “Create your first server” from the dashboard empty state or a Docs/Help link. Server create and credentials empty states point to the right guide. Content is accurate for the current app (credentials, server create flow, ready/setup script, deploy).

---

## Running agents (Round 2)

- **Agent I** (new cloud provider): One agent, one provider. Coordinate so only one of Linode/Vultr/Hetzner is implemented per codebase unless you want to support multiple; the brief allows picking one.
- **Agent J** (API): Independent; add Sanctum or custom tokens and routes.
- **Agent K** (email verification): Small; verify middleware and UX.
- **Agent L** (sessions): Independent; profile + sessions table.
- **Agent M** (docs/onboarding): Can run after or in parallel; references “credentials” and “server create” as they exist today.

Share this file with agents and assign **one section (one letter)** per agent. After all five are done, run `php artisan migrate`, `php artisan migrate:status`, and `php artisan test` to confirm nothing is broken.
