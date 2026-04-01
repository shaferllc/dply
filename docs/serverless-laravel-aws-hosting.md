# Hosting Laravel serverless targets on AWS (Bref vs Laravel Vapor)

This note now applies to the **root app**: where and how we think about **Laravel/PHP serverless deploys on AWS** after the reusable serverless engine was merged into the main BYO app. It remains separate from DigitalOcean Functions and other provider targets, which still route through the shared serverless deploy layer.

## Options

### [Bref](https://bref.sh/) (open source)

- Runs PHP on Lambda using Bref **layers** and/or container images.
- **Web (Laravel HTTP):** typically **PHP-FPM** runtime behind API Gateway / Lambda Function URL, or **Laravel Octane** via `bref/laravel-bridge`.
- **Queues / schedules:** separate Lambda functions (console runtime) or SQS-triggered workers—same patterns as any Lambda Laravel app.
- **You own** IaC: Serverless Framework, SAM, CDK, etc. No extra SaaS fee; AWS bill only.
- **Laravel integration:** [`bref/laravel-bridge`](https://github.com/brefphp/laravel-bridge) — handles Lambda-oriented defaults (e.g. storage under `/tmp`, logging, config cache). **v3** aligns with current Laravel versions; check Packagist for the latest constraint against **Laravel 13** before pinning (pre-release tags may be required until stable v3 ships).
- **Docs:** [Serverless Laravel – Getting started](https://bref.sh/docs/laravel/getting-started), [Laravel on Bref](https://bref.sh/docs/frameworks/laravel.html).

### [Laravel Vapor](https://vapor.laravel.com/) (“Laravel’s version”)

- **First-party** Laravel deployment product: provisions and manages Lambda, CloudFront, databases, caches, queues, assets from the **Vapor dashboard** and **Vapor CLI**.
- **`laravel/vapor-core`** in the application; deploy via `vapor` CLI authenticated to a Vapor account.
- **Trade-off:** subscription + strongest coupling to Laravel’s managed workflow; **benefit:** less low-level AWS/IaC work for a standard Laravel app.

## Recommendation for dply

| Goal | Lean toward |
| ---- | ----------- |
| **No Vapor subscription**, IaC in-repo, same mental model as “customer owns AWS” | **Bref** |
| **Fastest** path to production Lambda with Laravel-operated tooling | **Vapor** |
| **Spike / CI only** | Keep **traditional `php artisan serve` / FPM on a VM** until Phase D needs scale-to-zero |

**Default path for Laravel/PHP repos on AWS:** use **Bref** conventions for the customer deploy target while keeping the main control plane as a normal Laravel app unless there is a deliberate infrastructure cutover.

## Laravel 13 in this monorepo

The main repo now resolves Laravel/PHP repositories differently by target:

- **AWS Lambda**: supported and routed toward a Bref-oriented deploy config.
- **DigitalOcean Functions**: still detected, but blocked as unsupported for Laravel/PHP.

## Related

- [MULTI_PRODUCT_PLATFORM_PLAN.md](./MULTI_PRODUCT_PLATFORM_PLAN.md) §6, §9 Phase D
