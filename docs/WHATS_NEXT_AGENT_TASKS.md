# What’s Next – Agent Task Briefs

Each section is a self-contained task for a different agent. Paths are relative to project root (dply).

---

## Agent A: Destroy DigitalOcean droplet when server is removed

**Goal:** When a user deletes a server that was provisioned on DigitalOcean, destroy the droplet via the DO API so it stops billing; then delete the server record.

**Context:**
- `ServerController::destroy()` currently only deletes the `Server` model.
- `DigitalOceanService` has `destroyDroplet(int $id)` (see `app/Services/DigitalOceanService.php`).
- Server has `provider`, `provider_id`; when `provider === 'digitalocean'` and `provider_id` is set, the droplet exists on DO.

**Tasks:**
1. In `ServerController::destroy()`, before deleting the server: if `$server->provider === Server::PROVIDER_DIGITALOCEAN` and `$server->provider_id` is not empty, get the server’s `providerCredential`, instantiate `DigitalOceanService` with it, and call `destroyDroplet((int) $server->provider_id)`. Catch exceptions (e.g. droplet already gone) and log; still delete the server record.
2. Optionally dispatch a job to destroy the droplet so the request returns quickly; if so, delete the server in the job after successful destroy, or keep sync destroy and delete in the controller.
3. Ensure no regression: custom servers (no `provider_id`) still just delete the record.

**Acceptance:** Deleting a DO-provisioned server removes the droplet on DigitalOcean and the server row. Deleting a custom server only removes the row.

---

## Agent B: Set ssh_user for DigitalOcean-provisioned servers

**Goal:** DigitalOcean Ubuntu droplets use `root` for SSH. The provision job never sets `server.ssh_user`, so “Run command” can fail. Set `ssh_user` to `'root'` (or the correct user for the image) when provisioning.

**Context:**
- `app/Jobs/ProvisionDigitalOceanDropletJob.php` creates the droplet and updates the server with `provider_id`, `status`, `ssh_private_key`.
- `app/Jobs/PollDropletIpJob.php` sets `ip_address` and `status` when IP is available.
- Either place can set `ssh_user`. Default for Ubuntu on DO is `root`.

**Tasks:**
1. In `ProvisionDigitalOceanDropletJob`, when updating the server after creating the droplet, set `ssh_user` to `'root'` (or read from config, e.g. `config('services.digitalocean.ssh_user', 'root')`).
2. Optionally add `config/services.php` under `digitalocean`: `'ssh_user' => env('DIGITALOCEAN_SSH_USER', 'root')`.
3. Ensure existing `SshConnection` and “Run command” flow use `$server->ssh_user` (they already do; just confirm non-null).

**Acceptance:** New DO servers have `ssh_user` set so “Run command” works without manual DB edits.

---

## Agent C: Enforce subscription / server limits by plan

**Goal:** Gate server creation on the organization’s subscription (or free tier). E.g. free/Starter: max 3 servers; Pro: unlimited. No new server creation when over limit.

**Context:**
- Billing and plans live in `config/subscription.php`; org has `subscription('default')` and Stripe.
- `ServerController::store()` and the servers create UI are the insertion points.
- Decide rule set: e.g. no subscription or “free” → 3 servers; subscription with plan “pro_monthly” or “pro_yearly” → unlimited. Use plan id or Stripe price to decide.

**Tasks:**
1. Add a helper (e.g. on `Organization` or a service) such as `maxServers(): int` or `canCreateServer(): bool` that checks org’s subscription/plan and returns limit or false.
2. In `ServerController::store()`, before creating the server, check the limit; if over, redirect back with an error (e.g. “Server limit reached for your plan. Upgrade to add more.”).
3. In the server create view (Livewire or Blade), hide or disable “Create server” when at limit, and show an upgrade message or link to billing.
4. Document the limits (e.g. free = 3, pro = unlimited) in config or the helper so they’re easy to change.

**Acceptance:** Org at free-tier limit cannot create more servers and sees a clear message; Pro (or equivalent) can create without limit.

---

## Agent D: Server health / status (simple “is it up?”)

**Goal:** Show a simple health status for each server (e.g. “Reachable” / “Unreachable” or “Unknown”) so users can see which servers are up without running a command.

**Context:**
- Servers are in `Server` model with `ip_address`, `status` (pending, provisioning, ready, error, disconnected).
- “Run command” already uses SSH; a lightweight check could be: try SSH connect (or a quick TCP connect to ssh_port) with a short timeout and mark reachable/unreachable.

**Tasks:**
1. Add a way to compute or store “last reachable” or “health” (e.g. `server_health` table with `server_id`, `reachable_at`, `unreachable_at`; or a `last_ping_at` / `health_status` on `servers`). Prefer minimal schema change (e.g. two columns on `servers`: `last_health_check_at`, `health_status` = enum or string).
2. Add a job or scheduled command that, for servers with `status === ready` and an `ip_address`, attempts a quick SSH connect (or TCP socket to `ip_address:ssh_port`); update `last_health_check_at` and `health_status` (e.g. `reachable` / `unreachable`). Run via scheduler every 5–10 minutes, or on-demand from server list.
3. In the servers index and server show views, display the health status (e.g. badge or icon). If checks are only on-demand, add a “Check” button that dispatches the job and refreshes when done.
4. Keep the check very fast (e.g. 3–5 s timeout) so it doesn’t block the UI.

**Acceptance:** Server list and/or detail show a clear “Reachable” / “Unreachable” (or similar) and when last checked; no need to run a manual command to see if the server is up.

---

## Agent E: Server setup scripts (post-provision)

**Goal:** After a DigitalOcean server is `ready`, optionally run a setup script (e.g. install PHP, Node, or a custom script) so users get a prepared stack.

**Context:**
- `PollDropletIpJob` sets server to `ready` when the droplet has an IP.
- You have SSH and “Run command” via `SshConnection` and the server’s stored key.

**Tasks:**
1. Define a small “script” or “stack” model or config (e.g. id, name, script body or list of commands). Stored in DB or in `config/setup_scripts.php` as predefined options.
2. When creating a server (or in a new “Setup” step), let the user optionally select a script/stack (or “None”). Store choice on the server (e.g. `setup_script_id` or `setup_script_key`).
3. After `PollDropletIpJob` sets status to `ready`, if the server has a setup script selected, dispatch a job that SSHs in and runs the script (or runs a list of commands). On success/failure, update server (e.g. `setup_status` or a simple log). Consider timeouts and long-running scripts.
4. Show setup status (pending / running / done / failed) on server detail page.

**Acceptance:** User can pick a setup script when creating a DO server; once the server is ready, the script runs automatically and the UI shows setup status.

---

## Agent F: Deploy workflow (triggerable deploy command)

**Goal:** Add a “Deploy” action that runs a defined command or script (e.g. `git pull && composer install && php artisan migrate`) so users don’t have to type it in “Run command” each time.

**Context:**
- “Run command” already runs an arbitrary command via SSH.
- Servers belong to orgs; deploy script could be per-server or per-org.

**Tasks:**
1. Add a place to store the deploy command/script (e.g. `servers.deploy_command` text nullable, or `organizations.deploy_command` default). Optional: simple template with placeholders (e.g. branch name).
2. Server detail page: add a “Deploy” button that runs this command (or the default) via existing SSH/run-command flow. Show output and success/failure.
3. Optional: allow editing the deploy command in server settings or org settings.
4. Document a couple of example commands (Laravel, Node) in the UI or docs.

**Acceptance:** User can trigger a “Deploy” that runs a configured command and see the result without pasting into “Run command”.

---

## Agent G: Audit log (who did what per org)

**Goal:** Log important actions (server created/deleted, member invited, billing change, etc.) per organization for accountability and debugging.

**Tasks:**
1. Create `audit_logs` table: `organization_id`, `user_id`, `action` (string), `subject_type`/`subject_id` (nullable polymorphic), `old_values`/`new_values` (nullable JSON), `ip_address`, `created_at`.
2. Create an `AuditLog` model and a helper or trait to log actions (e.g. `audit('server.created', $server)`).
3. In relevant controllers (e.g. ServerController store/destroy, OrganizationInvitationController store/destroy, BillingController, TeamController), add audit log entries.
4. Add a simple “Activity” or “Audit log” view for the org (e.g. under organization show or settings) that lists recent entries with user, action, and time. Restrict to org admins/owners.

**Acceptance:** Creating/deleting servers, inviting members, and other key actions create audit records; org admins can view recent activity.

---

## Agent H: Two-factor authentication (2FA)

**Goal:** Allow users to enable TOTP 2FA so login (and optionally sensitive actions) require a code from an authenticator app.

**Tasks:**
1. Use Laravel Fortify’s two-factor features or a package (e.g. laravel/fortify with 2FA, or a dedicated 2FA package). Install and configure.
2. Add UI in profile/settings to enable 2FA (show QR code, verify code, confirm). Disable 2FA with password + code.
3. At login, if user has 2FA enabled, prompt for the code after password; verify and complete login.
4. Optionally require 2FA for destructive actions (e.g. delete server, change billing) if not already covered by “confirm password”.

**Acceptance:** Users can enable TOTP and must enter a code at login; optional extra check for sensitive actions.

---

## Running agents

- Run **Agent A** and **Agent B** first (quick wins).
- Then **Agent C** and **Agent D** (limits + health).
- Then **E, F, G, H** as needed, one or two at a time to avoid conflicts.

Share `docs/WHATS_NEXT.md` and this file with agents so they have full context.
