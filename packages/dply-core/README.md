# dply-core

Small, framework-light primitives shared across dply product apps. Boundaries: see `docs/adr/0001-dply-core-boundaries.md` in the main dply BYO application repository.

**Current surface (0.1.x)**

- `Dply\Core\Security\WebhookSignature` — HMAC-SHA256 `X-Dply-Signature` (timestamped + legacy body).
- `Dply\Core\Net\IpAllowList` — IPv4 exact + CIDR matching for webhook / API IP allow lists.

BYO-specific helpers (deploy SSH keys, deploy log redaction tied to this app’s pipelines, etc.) stay in the Laravel app until a second product needs the same behavior.

## Publish to `shaferllc/dply-core` (first time)

From this directory (not the monorepo root), if this tree is the package root:

```bash
git init
git add .
git commit -m "Initial dply-core 0.1.0"
git branch -M main
git remote add origin git@github.com:shaferllc/dply-core.git
git push -u origin main
git tag v0.1.0
git push origin v0.1.0
```

Create the empty `shaferllc/dply-core` repository on GitHub before pushing.

## Consume from GitHub (app without monorepo)

In the Laravel app `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/shaferllc/dply-core.git"
    }
],
"require": {
    "shaferllc/dply-core": "^0.1"
}
```

## Consume from monorepo path (current BYO app)

The main `dply` app uses a **path** repository pointing at `./packages/dply-core` so installs work before the Git remote exists. After you publish tags, other apps can use **VCS** as above.

## Run tests (package only)

```bash
cd packages/dply-core
composer install
./vendor/bin/phpunit
```
