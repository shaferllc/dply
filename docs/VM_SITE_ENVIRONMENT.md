# Site environment variables

The **Environment** section stores **key/value config** injected on deploy and available to the app process.

## Add variables

1. Enter **Key** (e.g. `APP_ENV`) and **Value**.
2. Click **Save** or **Add**.

Secrets should use dedicated secret UI where marked; values may be write-only after save.

## Deploy application

Changes apply on the **next deploy** unless the runtime supports hot reload. Redeploy from **Deploy** or Overview to pick up new vars immediately.

## `dply.yaml` env

Read-only **`env`** declarations in repo config sync on deploy — dashboard edits and repo config must stay aligned.

## Related sections

- **Deploy** — build-time vs runtime injection
- **Laravel** — `APP_KEY`, `DB_*` defaults
- **Databases** — create credentials to reference here
