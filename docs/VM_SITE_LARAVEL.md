# Laravel stack

The **Laravel** section appears when dply detects a **Laravel** app in the repository. It shortcuts framework-specific operations.

## Common actions

- **`php artisan migrate`** — run pending migrations on deploy or on demand
- **`config:cache` / `route:cache`** — optimize commands
- **Queue** links to **Queue workers**
- **Scheduler** link to **Cron jobs** with recommended `schedule:run` entry

## APP_KEY

New deploys may auto-run **`key:generate`** when missing to prevent 500 errors — still set **`APP_KEY`** in **Environment** for consistency across releases.

## Zero-downtime

Use **atomic deploy** with **`php artisan migrate --force`** in deploy hooks after symlink flip.

## Related sections

- **Runtime → PHP** — version matching `composer.json`
- **Environment** — `APP_*`, `DB_*`, `REDIS_*`
- **Logs** — `storage/logs/laravel.log`
