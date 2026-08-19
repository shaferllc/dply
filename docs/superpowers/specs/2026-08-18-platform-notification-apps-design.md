# Platform notification apps (make the buttons live)

Status: **approved to build.** First slice of the connector catalog.

## Goal

A platform-admin **Connections** page where you save Slack / Discord / Telegram *app* credentials. Once saved, org notification UIs show **Add to Slack**, **Add to Discord**, and **Connect Telegram** instead of “paste a webhook.”

`.env` remains the fallback. The UI overlays it. Do not write `.env`.

## Out of v1

Org-level reusable connections, site-resource inject, Cloudflare Email Create, Git/provider/Stripe platform apps, a generic connector package.

## Store

`platform_connections`: unique `provider` (`slack` | `discord` | `telegram`), encrypted `config` JSON, `last_ok_at`, `last_error`.

Resolver merges stored non-empty fields over `config/services.php` env values. OAuth controllers and bot clients read the resolver, not raw `config()` for those keys.

## UI

`/admin/connections` (platform admin). Three cards. Secrets write-never after save (blank = keep). Test: Telegram `getMe`; Slack/Discord = required fields present. Telegram: optional **Register webhook** (HTTPS URL). Show callback URLs to paste into the vendor dashboard.

## Trust

Same as other org secrets: encrypted at rest with `APP_KEY`. dply can decrypt.
