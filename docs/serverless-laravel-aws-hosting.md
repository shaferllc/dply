# Hosting the Serverless Laravel app on AWS (Bref vs Laravel Vapor)

This note applies to **`apps/dply-serverless`**: where and how we run **our** control-plane Laravel app on **AWS**. It is separate from **customer** FaaS deploys (Lambda/DO Functions), which go through **`ServerlessFunctionProvisioner`** and the AWS/DO SDKs.

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

**Default for Phase D spikes:** run the app on a normal PHP process or container. Add **Bref** (or Vapor) when we explicitly cut over **dply-serverless** production to Lambda.

## Laravel 13 in this monorepo

`apps/dply-serverless` tracks **`laravel/framework` ^13** and **already requires** **`bref/bref`** + **`bref/laravel-bridge`** (^3) for Lambda deployability. **`laravel/octane`** is pulled in by the bridge—review Octane/Bref docs if you customize workers.

**`shaferllc/dply-core`** is wired as a **path** repository only (`../../packages/dply-core`) in `apps/dply-serverless/composer.json` — no VCS fallback until you opt in.

## Related

- [MULTI_PRODUCT_PLATFORM_PLAN.md](./MULTI_PRODUCT_PLATFORM_PLAN.md) §6, §9 Phase D
- [apps/dply-serverless/README.md](../apps/dply-serverless/README.md)
