# dply Serverless (spike)

Second product app in the monorepo: **its own Laravel install, its own database**, Composer dependency on [`shaferllc/dply-core`](../../packages/dply-core).

## Local setup

```bash
cd apps/dply-serverless
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure MySQL with DB_DATABASE=dply_serverless
php artisan migrate
php artisan test
php artisan serve
```

Set **`SERVERLESS_PROVISIONER`** (`local`, `aws`, or `digitalocean`) to exercise stub provisioners; see `config/serverless.php`. Visit `/internal/spike` to verify `dply-core`, the **`DeployEngine`** → **`ServerlessDeployEngine`** path, and the bound `ServerlessFunctionProvisioner` (remove or gate this route before any public deploy).

## Hosting this app on AWS Lambda (Bref vs Laravel Vapor)

**Not** the same as deploying **customer** functions (that is `ServerlessFunctionProvisioner` + AWS SDK).

- **[Bref](https://bref.sh/)** — open source; `bref/bref` + [`bref/laravel-bridge`](https://github.com/brefphp/laravel-bridge) (v3 line supports Laravel 10–13; confirm stable tag on Packagist before pinning). You own SAM/Serverless/CDK.
- **[Laravel Vapor](https://vapor.laravel.com/)** — Laravel’s managed serverless product (`vapor-core` + CLI + subscription).

See **[docs/serverless-laravel-aws-hosting.md](../../docs/serverless-laravel-aws-hosting.md)** for a decision summary. Composer **`suggest`** in this app’s `composer.json` points at Bref when you are ready to `composer require`. **`shaferllc/dply-core`** is **path-only** (`../../packages/dply-core`); add a VCS repository later if you publish the package.

## Deploy pipeline (later)

This directory is a **separate deployable** from the BYO app at the repository root. Point CI/CD and `DB_*` at a **dedicated** database instance — see [database isolation runbook](../../docs/runbooks/database-isolation.md).
