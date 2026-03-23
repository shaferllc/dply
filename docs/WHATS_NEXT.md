# What’s next for dply

Prioritized ideas for the server-creation site, based on what’s already built and what’s promised (e.g. on the pricing page).

---

## High impact / already promised

### 1. Server setup scripts (pricing page: “coming soon”)
- **Idea:** After a server is `ready`, run a configurable setup script (or pick a “stack”: e.g. PHP, Node, static site).
- **Options:** Cloud-init / user-data in `ProvisionDigitalOceanDropletJob`, or a post-provision job that SSHs in and runs a script (from DB or a small script library).
- **Value:** One-click app stacks instead of manual SSH setup.

### 2. Destroy DigitalOcean droplet when server is removed
- **Current:** `ServerController::destroy()` only deletes the server record; the droplet keeps running and billing.
- **Change:** For `provider === digitalocean` and non-null `provider_id`, call `DigitalOceanService::destroyDroplet()` (or a job) before deleting the server. Optionally soft-delete and offer “destroy in 24h” for safety.
- **Value:** No orphaned droplets or surprise bills.

### 3. Set `ssh_user` for DigitalOcean-provisioned servers
- **Current:** Provision job never sets `ssh_user`; Ubuntu on DO uses `root`. If `ssh_user` is null, “Run command” can break.
- **Change:** In `ProvisionDigitalOceanDropletJob` (or when updating the server after provisioning), set `ssh_user` to `'root'` (or make it configurable per image).
- **Value:** Run command and any SSH-based features work reliably.

---

## Product and UX

### 4. Deploy workflow (“Run command” → “Deploy”)
- **Idea:** A “Deploy” action that runs a defined command (e.g. `git pull && composer install && php artisan migrate`) or a small script, with optional branch/env.
- **Building on:** Existing “Run command” and SSH; add a “deploy script” or template per server (or per team).
- **Value:** Matches the homepage “php artisan deploy” story and reduces manual SSH.

### 5. Server health / status
- **Idea:** Lightweight “is it up?” checks (e.g. ping or HTTP) and show status on server list/detail. Optional: simple uptime or “last seen” and maybe an alert if unreachable.
- **Value:** Clear view of which servers are live without opening a terminal.

### 6. More cloud providers
- **Current:** DigitalOcean + “custom” (existing server with IP + SSH key).
- **Ideas:** Linode, Vultr, AWS (EC2), Hetzner. Same pattern: provider credential, provision job, poll for IP.
- **Value:** Broader audience and less lock-in.

---

## Platform and safety

### 7. Enforce subscription / server limits
- **Current:** Billing and plans exist; server creation isn’t gated by plan.
- **Idea:** Check org subscription (or “starter” free tier) before creating a server; enforce limits (e.g. 3 servers on free, unlimited on Pro). Apply in `ServerController::store` and in the create UI.
- **Value:** Aligns usage with pricing and avoids abuse.

### 8. Two-factor authentication (2FA)
- **Idea:** TOTP (e.g. Laravel Fortify or a package) for login and/or sensitive actions (e.g. delete server, change billing).
- **Value:** Stronger security for teams and production servers.

### 9. API for CI/CD
- **Idea:** API tokens (per user or per org) to list servers, trigger a “deploy” endpoint, or run a predefined command. Rate limits and scopes.
- **Value:** GitHub Actions, GitLab CI, or other pipelines can deploy without storing SSH keys in CI.

### 10. Audit log
- **Idea:** Log who did what (server created/deleted, member invited, billing changed, etc.) per organization. Store in a simple `audit_logs` table and show in org/settings.
- **Value:** Accountability and debugging in teams.

---

## Nice to have

- **Email verification:** Required for all app use; see [VERIFICATION.md](VERIFICATION.md) for how resend/verify works.
- **Session management:** List/revoke sessions in profile or org settings.
- **Docs/onboarding:** Short “Create your first server” and “Connect DigitalOcean” guides linked from dashboard or first-time empty state.

---

## Suggested order

1. **Quick wins:** (2) Destroy droplet on delete, (3) Set `ssh_user` for DO servers.  
2. **Differentiator:** (1) Server setup scripts or (4) Deploy workflow.  
3. **Business:** (7) Enforce plan limits.  
4. **Scale:** (5) Health/status, (6) More providers, (9) API, (8) 2FA, (10) Audit log as needed.

You can treat this as a living list and reorder or slice by quarter.
