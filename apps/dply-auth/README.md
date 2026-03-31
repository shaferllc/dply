# dply Auth (`auth.dply.io`)

Central **identity** app for the dply platform: **email/password**, **password reset**, **two-factor authentication** (Fortify), and **OAuth 2** token issuance (**Laravel Passport**) for separate product apps (BYO, Serverless, Cloud, WordPress, Edge).

Each product keeps its **own database and billing**; this app holds **users** and issues **access tokens** so products can recognize the same person after the OAuth redirect + code exchange.

## Docs

- **[Central auth architecture](../../docs/DPLY_CENTRAL_AUTH.md)** — flows, env vars, integration notes.
- **PKCE helpers** live in `packages/dply-core`: `Dply\Core\Auth\OAuthPkce`.

## Local setup

```bash
cd apps/dply-auth
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # or configure MySQL
php artisan migrate
php artisan db:seed              # test user + OAuth clients for each product name
php artisan serve
```

- **Authorize UI:** Passport 13 is “headless”; this app registers Blade views under `resources/views/auth/oauth/` via `Passport::viewPrefix('auth.oauth')`.
- **OAuth clients:** `DplyOAuthClientsSeeder` creates confidential **authorization code** clients. Retrieve **client id** / **secret** from the DB or create with `php artisan passport:client` — store per product in secrets, not in Git.

## Endpoints (Passport)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET/POST | `/oauth/authorize` | Start OAuth / approve |
| POST | `/oauth/token` | Exchange code for tokens |
| POST | `/oauth/token/refresh` | Refresh (auth middleware) |
| GET | `/api/user` | Current resource owner (Bearer access token; scope `read-user`) |

Fortify routes: `/login`, `/register`, `/two-factor-challenge`, etc. (see `config/fortify.php`).

## Environment

| Variable | Purpose |
| -------- | ------- |
| `APP_URL` | Public URL of this app (e.g. `https://auth.dply.io`) |
| `DPLY_OAUTH_REDIRECT_*` | Optional overrides for seeded client redirect URIs (see `DplyOAuthClientsSeeder`) |

## Relationship to BYO (repository root)

The main BYO app may continue to host users until you run an **account migration** or **federation** project. This package is the **target** central IdP shape; wiring BYO to use it is a separate rollout.
