# Connected app bindings (site Environment)

Status: **building.** Site Resources / first-deploy Environment step.

## Goal

Operators attach generic SaaS keys (Slack, Discord, Telegram, Google Drive, Dropbox) on a site the same way they attach AI or SMS. Keys inject at deploy, stay out of the editable Variables list, and can be saved once per org for reuse.

This is **not** notification-channel OAuth (alerts *to* Slack). This is **app credentials** the site itself reads.

## Type

`connected_app` — multi-instance, keyed by provider (namespaces do not collide).

## Providers (v1)

| Provider | Required | Injected |
|---|---|---|
| Slack | bot token **or** webhook | `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_TOKEN`, `SLACK_WEBHOOK_URL`, `SLACK_BOT_USER_DEFAULT_CHANNEL` |
| Discord | bot token **or** webhook | `DISCORD_BOT_TOKEN`, `DISCORD_WEBHOOK_URL` |
| Telegram | bot token | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` |
| Google Drive | client id, secret, refresh token | `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`, `GOOGLE_DRIVE_FOLDER` |
| Dropbox | access token | `DROPBOX_ACCESS_TOKEN`, `DROPBOX_APP_KEY`, `DROPBOX_APP_SECRET` |

## Surfaces

- Resources hub **Add resource → Integrations → Connected apps**
- Setup Environment step: suggestion when those keys are scanned, plus an always-on **Add Slack, Discord, Drive…** action
