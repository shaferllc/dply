# dply self-manages dply — operational runbook

dply manages its own prod control-plane: off-box secret escrow, DB backups, and
(eventually) self-deploy run through dply's own features. `deploy.sh` is kept as
**dormant break-glass** — the only non-circular way back in if a self-deploy
bricks the box. See the plan: `~/.claude/plans/we-need-a-env-iridescent-marble.md`.

> Why this exists: the box's SSH keys, DB admin creds, and every secret are
> encrypted in the Postgres **on that box**, under `APP_KEY` which lives in
> `shared/.env` **on that box**. Lose the box → it's all locked inside. The W1
> off-box escrow below is what breaks that loop. Do W1 first.

---

## W1 — Off-box break-glass (do this first)

### One-time: age key custody (asymmetric)
1. `age-keygen -o identity.txt` on an **offline** machine. Store `identity.txt`
   (the private key) in 1Password **and** print it; never put it on a prod box.
2. Put the **public** recipient (`age1…`) in `shared/secrets/age-recipients.txt`
   on each box and set `SECRET_VAULT_RECIPIENTS_PATH` to it. Install `age` and set
   `SECRET_VAULT_AGE_BIN`.

### One-time: stores (set in shared/.env)
- Object (primary, **separate cloud account**, versioned + object-locked, box IAM
  = PutObject+ListBucket only): `SECRET_VAULT_OBJECT_*`.
- Git ops repo (ciphertext only): `SECRET_VAULT_GIT_*`.
- 1Password: `SECRET_VAULT_OP_*`.
- Fast break-glass bundle: `SECRET_VAULT_CRITICAL_SSH_KEY_PATH`,
  `SECRET_VAULT_CRITICAL_PG_PASSWORD` (+ `_PG_SUPERUSER`).
- Dead-man's-switch: `SECRET_VAULT_DMS_*`; failures → `SECRET_VAULT_ALERT_WEBHOOK`.

### Scheduled automatically (DplySchedule)
- `secrets:escrow --source=platform-env` (daily) — .env → APP_KEY off-box.
- `secrets:escrow --source=db-dump` (daily) — independent age-encrypted pg_dump.
- `secrets:escrow --source=critical-keys` (daily) — recovery SSH key + pg superuser.
- `secrets:restore-drill` (daily, **drill host only**: `SECRET_VAULT_DRILL_ENABLED=1`).

### Restore (break-glass)
```
# On a machine WITH the offline age identity (SECRET_VAULT_IDENTITY_PATH):
php artisan secrets:restore --source=platform-env --version=latest --to=/path/.env --identity=…
php artisan secrets:restore --source=critical-keys --version=latest --to=/tmp/keys.json --identity=…
# DB: restore the latest escrowed dump (see W3 dply:db:restore, or psql the decrypted dump).
```

---

## W2 — Adopt prod as a managed Server
```
php artisan dply:self:adopt   # idempotent; reads prod box details from config/env
```
Creates/syncs the `Server` (ip, ssh user, operational + recovery keys), the
`ServerDatabaseAdminCredential` (postgres superuser), and a `ServerDatabase` row
for the control-plane Postgres. Record the resulting server IDs into
`secret_vault.drift.targets` (web + worker) so drift-check covers them.

---

## W3 — dply-native DB backup + restore
- Create a `BackupConfiguration` → the separate-account bucket.
- Create a daily `ServerBackupSchedule` for the control-plane `ServerDatabase`.
  Backups run via `RunBackupScheduleCommand` → `ExportServerDatabaseBackupJob`.
- Restore: `php artisan dply:db:restore {backup}` (guarded; imports into a target DB).
- The W1 age dump is the **independent** copy (survives the backup provider/account).

---

## W4 — Self-deploy (deploy.sh stays as break-glass)
- Onboard dply as a VM **Site** on the prod Server: repo + branch + deploy key,
  `deploy_strategy=atomic`, `env_file_path` = **external** `shared/.env` (never
  per-release), health check `/up` + `deploy_health_auto_rollback=true`.
- Deploy steps cover the deploy.sh parity gaps: `composer install`, `npm ci` +
  `npm run build`, `migrate`, `config:cache`/`route:cache`/`event:cache`/`view:cache`,
  `pennant:clear`; shared `storage/` symlinked across releases.
- Pre-deploy `secrets:check-drift` must pass (hard-fail on APP_KEY value drift).
- Workers: their own Site(s); deploy web first, then workers.
- **Always validate on a staging Site/box first**, then cut prod over.

### Break-glass: a self-deploy bricked prod
1. SSH in with the recovery key (from `critical-keys` escrow if needed).
2. `git -C <ROOT>/repo fetch` and run `./deploy.sh "break-glass"` — the dormant
   atomic-release path still works and is independent of the app being healthy.
3. If `APP_KEY`/.env is the problem, restore `shared/.env` from W1 escrow.

---

## W5 — APP_KEY rotation (routine, online)
```
# 1. Generate new key; on EVERY site's env set APP_KEY=<new>, APP_PREVIOUS_KEYS=<old>.
# 2. Re-encrypt all stored secrets under the new key (resumable):
php artisan secrets:reencrypt --show-plan     # eyeball coverage
php artisan secrets:reencrypt                 # online, chunked
php artisan secrets:reencrypt                 # second pass should report 0
# 3. Gate before retiring the old key — must pass:
php artisan secrets:reencrypt --assert-complete
# 4. Remove APP_PREVIOUS_KEYS everywhere; redeploy config:cache.
```
Coverage is enforced by `tests/Unit/SecretReencryptCoverageTest.php` — if a new
`Crypt::encrypt`/`encrypt()` write site appears unclassified, the test fails until
it's added to `config/secret_vault.php` (`raw_crypt`/`json_crypt`) or the allowlist.
