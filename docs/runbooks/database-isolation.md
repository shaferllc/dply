# Runbook: database isolation per product app

> **Superseded 2026-04-28.** The multi-app + per-product-database direction was retired; the platform now ships from a single Laravel application using a single database. This runbook is kept for historical reference. If storage isolation is reintroduced later, it will use **named Laravel database connections** within the same app, and a fresh runbook will replace this one.

---

Ensure **no deployable** points `DB_*` at another product’s database. Use this when creating environments, rotating secrets, or after infra incidents.

## 1. Naming convention (locked)

Align with [MULTI_PRODUCT_PLATFORM_PLAN.md §8](../MULTI_PRODUCT_PLATFORM_PLAN.md):

| Product    | Suggested `DB_DATABASE` (example) | Env marker (recommended) |
| ---------- | --------------------------------- | ------------------------ |
| BYO        | `dply_byo` / `dply_byo_staging`   | `APP_PRODUCT=byo`        |
| Serverless | `dply_serverless`                 | `APP_PRODUCT=serverless` |
| Cloud      | `dply_cloud`                      | `APP_PRODUCT=cloud`      |
| WordPress  | `dply_wordpress`                  | `APP_PRODUCT=wordpress`  |
| Edge       | `dply_edge`                       | `APP_PRODUCT=edge`       |

**Rule:** `DB_DATABASE` must **start with** the agreed prefix for that product (or match an explicit allowlist). Two apps must never use the **same** `DB_DATABASE` string.

## 2. CI check (recommended)

### 2a. Static: env template vs product

For each app in the monorepo (e.g. `apps/dply-by`, `apps/dply-edge`):

1. Commit a **non-secret** template: `.env.example` or `.env.ci` with placeholder passwords but **real naming** for `DB_DATABASE`.
2. Add a small **allowlist file** next to the app, e.g. `product.json`:

```json
{
  "product": "byo",
  "db_database_prefix": "dply_byo"
}
```

3. In CI (GitHub Actions, GitLab, etc.), run a step **before tests**:

```bash
# Example: app root is apps/dply-by
PRODUCT=$(jq -r .product product.json)
PREFIX=$(jq -r .db_database_prefix product.json)
# Load only DB_DATABASE from .env.example (adjust if you use .env.ci)
DB_NAME=$(grep -E '^DB_DATABASE=' .env.example | cut -d= -f2- | tr -d '\r"')
case "$DB_NAME" in
  ${PREFIX}*) echo "OK: $DB_NAME matches $PREFIX";;
  *) echo "FAIL: DB_DATABASE=$DB_NAME must start with $PREFIX for product=$PRODUCT"; exit 1;;
esac
```

4. **Production secrets** are not in git; mirror the same **prefix rule** in deploy scripts: when injecting `DB_DATABASE` from Terraform/1Password, assert it matches `product.json` for that service.

### 2b. Dynamic: Laravel config (optional)

In CI, with a **throwaway** DB or sqlite:

```bash
cp .env.example .env
php artisan config:clear
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$db = config('database.connections.' . config('database.default') . '.database');
\$prefix = getenv('EXPECTED_DB_PREFIX') ?: '';
if (\$prefix && strncmp(\$db, \$prefix, strlen(\$prefix)) !== 0) {
    fwrite(STDERR, \"DB mismatch: \$db vs expected prefix \$prefix\\n\");
    exit(1);
}
echo \"OK: \$db\\n\";
"
```

Pass `EXPECTED_DB_PREFIX` from the matrix row for that app.

## 3. Manual runbook (every new env)

Before marking **staging/production** ready:

- [ ] Open secrets store for **this** app only; confirm `DB_DATABASE` / `DATABASE_URL` path includes the correct product name (not copy-paste from another service).
- [ ] From a **bastion or worker** (not public web), connect with the same credentials the app uses; run `SELECT DATABASE();` (MySQL) or `current_database()` (Postgres). Compare to the allowlist for this product.
- [ ] Confirm **queue workers** and **scheduler** for this app use the **same** `.env` / secret bundle as the web tier (no orphaned worker with old DB).
- [ ] After first deploy, run `php artisan migrate:status` and confirm migrations are **only** this app’s (no foreign tables from another product—if you see them, wrong DB).

## 4. Incident: suspected wrong DB

1. **Stop** traffic to the affected deploy (scale to 0 or maintenance) if writes may be crossing products.
2. Capture current `DB_DATABASE`, `DB_HOST`, and `APP_PRODUCT` from runtime env (not from git).
3. Compare to runbook table; rotate credentials if secrets were shared.
4. Restore from backup **per product**; do not assume one DB dump covers multiple products if they were wrongly merged.

## 5. Optional app config snippet

Add to `config/dply.php` (or similar):

```php
<?php

return [
    'product' => env('APP_PRODUCT', 'byo'),
    'allowed_database_prefixes' => [
        'byo' => ['dply_byo'],
        'serverless' => ['dply_serverless'],
        // …
    ],
];
```

Production boot (pseudo-code):

```php
if (app()->environment('production')) {
    $product = config('dply.product');
    $db = config('database.connections.' . config('database.default') . '.database');
    $ok = collect(config('dply.allowed_database_prefixes.' . $product, []))
        ->contains(fn ($p) => str_starts_with($db, $p));
    abort_unless($ok, 503, 'Database configuration mismatch');
}
```

Keep allowlists **short**; update when adding a new product line.

## Related

- [ADR-005: database per product deploy](../adr/0005-database-per-product-deploy.md)
