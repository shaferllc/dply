# Central authentication (`auth.dply.io`)

Separate product apps (BYO, Serverless, Cloud, WordPress, Edge) keep **their own databases and billing**. **Identity** (email/password, 2FA, password reset) is centralized in **`apps/dply-auth`**, deployed at a dedicated host such as **`https://auth.dply.io`**.

## Pattern

- **OAuth 2.1-style authorization code flow** with **PKCE** for public or native clients; **confidential** server-side apps use **client id + secret** on the token exchange.
- **Laravel Passport** on `dply-auth` issues access tokens; product apps validate tokens or call auth APIs as you wire them.

## Flow (per product)

1. User clicks **Log in** on e.g. `edge.dply.io`.
2. Product app stores PKCE `code_verifier` in session, redirects browser to:
   `GET https://auth.dply.io/oauth/authorize?response_type=code&client_id=...&redirect_uri=...&scope=...&state=...&code_challenge=...&code_challenge_method=S256`
3. User signs in (and completes 2FA) on **auth** via **Fortify** (session cookie only on `auth.dply.io`).
4. User approves the client (Passport authorization view).
5. Auth redirects to `redirect_uri?code=...&state=...`.
6. Product backend `POST`s to `https://auth.dply.io/oauth/token` with `grant_type=authorization_code`, `code`, `redirect_uri`, and (if confidential) `client_id` + `client_secret`, plus `code_verifier` for PKCE. Request scope **`read-user`** on `/oauth/authorize` so the access token is allowed to call `/api/user`.
7. Product backend `GET`s `https://auth.dply.io/api/user` with `Authorization: Bearer {access_token}` to read `id`, `name`, `email`, and `email_verified_at`, then establishes **its own** session (or API guard) — **no shared user table** across products unless you add sync later.

## Monorepo layout

| Piece | Role |
| ----- | ---- |
| `apps/dply-auth` | Passport + Fortify; own `users` table; OAuth routes under `/oauth/*`. |
| `packages/dply-core` | `Dply\Core\Auth\OAuthPkce` for code verifier / S256 challenge. |
| Product apps | Register callback routes; env: `DPLY_AUTH_URL`, OAuth client id/secret from Passport. |

## Seeded OAuth clients

`DplyOAuthClientsSeeder` creates **authorization code** clients named `dply-byo`, `dply-edge`, etc. Run:

```bash
cd apps/dply-auth
php artisan db:seed --class=DplyOAuthClientsSeeder
```

Retrieve **client id** and **plain secret** from the `oauth_clients` table (or create clients with `php artisan passport:client`). Store secrets in each product’s **secret manager / .env** — never commit.

## BYO (repository root) — OAuth federation

When `DPLY_AUTH_ENABLED=true`, the BYO app exposes:

- `GET /oauth/central/redirect` — starts the authorization code + PKCE flow (session stores verifier + `state`).
- `GET /oauth/callback` — exchanges the code, calls `GET {DPLY_AUTH_URL}/api/user`, then signs the user into BYO.

Configuration lives in `config/dply_auth.php`. Environment variables:

| Variable | Purpose |
| -------- | ------- |
| `DPLY_AUTH_ENABLED` | Set `true` to show **Continue with dply account** on the login page and enable routes. |
| `DPLY_AUTH_URL` | Origin of `dply-auth` (e.g. `https://auth.dply.io` or `http://dply-auth.test`). |
| `DPLY_AUTH_CLIENT_ID` / `DPLY_AUTH_CLIENT_SECRET` | Passport **dply-byo** client (from `oauth_clients` after seeding). |
| `DPLY_AUTH_REDIRECT_URI` | Optional. Defaults to `{APP_URL}/oauth/callback` — must match a registered redirect URI on the client. |

Local users are linked by `users.dply_auth_id` (Passport resource owner id) or, on first login, by matching **email**. New users get a random password hash (password login remains available if they set a password in BYO).

Integration helpers: `Dply\Core\Auth\CentralOAuthClient` and `Dply\Core\Auth\OAuthPkce` in `packages/dply-core`.

### Other product apps (Serverless, Cloud, WordPress, Edge)

Use the same OAuth client pattern: register each app’s `/oauth/callback` in Passport (`DplyOAuthClientsSeeder` or `passport:client`), copy the env vars with that app’s client id/secret, and implement the same redirect + callback flow (Guzzle token exchange + `GET /api/user`). Product apps keep their own `users` table; link accounts the same way as BYO.

## BYO migration note

The **repository-root BYO app** can still use local email/password and Git OAuth. Central sign-in is **additive** when `DPLY_AUTH_ENABLED` is on. A full cutover (only `dply-auth` accounts) is a separate product decision.

## References

- [Laravel Passport](https://laravel.com/docs/passport)
- [OAuth 2.1 draft](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1-09) (PKCE required for public clients)
