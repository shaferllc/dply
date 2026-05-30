# Site queue workers

The **Queue workers** section configures **Supervisor-managed queue consumers** for this site — common for Laravel Horizon/queue:work patterns.

## Worker templates

Add workers with:

- **Connection** — Redis, database, etc.
- **Queue names** — `default`, `high`, etc.
- **Processes** — parallel worker count
- **Timeout** and **sleep** settings

## Laravel defaults

When Laravel is detected, suggested commands pre-fill **`php artisan queue:work`** with the site's PHP binary.

## Supervisor required

Sidebar shows **needs setup** until Supervisor is installed on the server.

## Restart on deploy

Enable **Restart workers on deploy** where exposed so new code loads after atomic releases.

## Related sections

- **Daemons** — non-queue long runners
- **Environment** — `REDIS_HOST`, queue connection vars
- **Laravel** — Horizon and scheduler links
