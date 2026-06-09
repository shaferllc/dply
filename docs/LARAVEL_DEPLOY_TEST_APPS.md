# Laravel open-source apps for deploy testing

Curated list of real-world Laravel projects useful for validating Dply BYO VM site provisioning, git deploy, build steps, queues, scheduler, and webserver config. Pick a few from each tier rather than running all 20 at once.

## Quick picks (start here)

| Tier | Apps | What you exercise |
|------|------|-------------------|
| **Smoke** | [laravel/laravel](https://github.com/laravel/laravel), [LinkAce](https://github.com/Kovah/LinkAce), [SolidTime](https://github.com/solidtime-io/solidtime) | Provision, first deploy, migrate, Vite build |
| **Production-shaped** | [Snipe-IT](https://github.com/snipe/snipe-it), [BookStack](https://github.com/BookStackApp/BookStack), [Monica](https://github.com/monicahq/monica) | Queues, scheduler, uploads, env surface |
| **Stress** | [Pterodactyl Panel](https://github.com/pterodactyl/panel), [Invoice Ninja](https://github.com/invoiceninja/invoiceninja), [Bagisto](https://github.com/bagisto/bagisto) | Redis, long migrations, heavy `npm run build`, workers |
| **Weird / edge** | [October CMS](https://github.com/octobercms/october), [Flarum](https://github.com/flarum/flarum), [Koel](https://github.com/koel/koel) | Non-standard layouts, extensions, system packages |

---

## Top 20

### Baseline & modern stacks

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 1 | **Laravel** (fresh app) | https://github.com/laravel/laravel | Smoke test: migrate, `public/`, env, zero-downtime symlink, placeholder → first deploy |
| 2 | **SolidTime** | https://github.com/solidtime-io/solidtime | Modern Laravel + Vite; good “current stack” baseline |
| 3 | **LinkAce** | https://github.com/Kovah/LinkAce | Medium complexity, cron, tags; typical self-hosted doc pattern |

### Livewire / Filament / Inertia

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 4 | **Filament demo** | https://github.com/filamentphp/demo | Livewire + Filament admin; asset build, auth, panel routing |
| 5 | **BookStack** | https://github.com/BookStackApp/BookStack | Large production app; file uploads, permissions, optional LDAP |
| 6 | **Monica** | https://github.com/monicahq/monica | Personal CRM; scheduler/reminders, queues, frontend build |

### Queues, Redis, scheduler

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 7 | **Snipe-IT** | https://github.com/snipe/snipe-it | Common self-hosted deploy; LDAP, queues, uploads, long migrations |
| 8 | **Pterodactyl Panel** | https://github.com/pterodactyl/panel | Heavy ops app; Redis, queues, scheduler, daemon integration |
| 9 | **Cachet** | https://github.com/cachethq/cachet | Status page; Redis/cache, incident workflows |
| 10 | **FreeScout** | https://github.com/freescout-helpdesk/freescout | Helpdesk; mail queues, IMAP, background jobs |

### Billing, finance, commerce

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 11 | **Invoice Ninja** | https://github.com/invoiceninja/invoiceninja | Large codebase; API, PDF generation, multi-company, queue-heavy |
| 12 | **Firefly III** | https://github.com/firefly-iii/firefly-iii | Personal finance; cron imports, queues, strict env config |
| 13 | **Akaunting** | https://github.com/akaunting/akaunting | Modular accounting; plugin system, installer flow |
| 14 | **Crater** | https://github.com/crater-invoice/crater | Invoicing SPA; API + frontend build split |
| 15 | **Bagisto** | https://github.com/bagisto/bagisto | Full e-commerce; catalog, checkout, heavy migrations & assets |

### CMS, forums, media

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 16 | **October CMS** | https://github.com/octobercms/october | CMS routing/plugins; different docroot & plugin layout |
| 17 | **Winter CMS** | https://github.com/wintercms/winter | October fork; same class of deploy quirks |
| 18 | **Flarum** | https://github.com/flarum/flarum | Forum; extensions via Composer, `public` + API, queue worker |
| 19 | **Koel** | https://github.com/koel/koel | Media streaming; **ffmpeg** on server, asset pipeline |

### Event / SaaS-shaped

| # | Project | Repository | Why test it |
|---|---------|------------|-------------|
| 20 | **HiEvents** | https://github.com/HiEventsDev/HiEvents | Event ticketing; Stripe, mail, queues, modern Laravel monolith |

---

## Dply-specific checklist

Most apps above expect some combination of:

- **Database** — MySQL or PostgreSQL (`DB_*` in site Environment)
- **Redis** — queues/cache (`REDIS_*`, Daemons for `queue:work` or Horizon)
- **Scheduler** — `* * * * * php artisan schedule:run` (site Cron or server Cron)
- **Build** — `composer install --no-dev`, `npm ci && npm run build` in deploy pipeline
- **Migrations** — `php artisan migrate --force` in deploy hooks (especially with **atomic** deploy)
- **Storage** — `storage/` and `bootstrap/cache/` writable by deploy user

### Suggested validation order

1. **laravel/laravel** — confirm provision + placeholder + first deploy end-to-end.
2. **BookStack** or **LinkAce** — add scheduler + one queue worker; verify 500 error page and logs.
3. **Snipe-IT** or **Monica** — atomic deploy, migrate hook, file uploads.
4. **Bagisto** or **Invoice Ninja** — stress build time, migration duration, worker memory.
5. **Koel** or **Pterodactyl** — optional server packages (ffmpeg, extra services) after core PHP path is stable.

### Zero-downtime candidates

Good apps to test **`deploy_strategy = atomic`** (release dirs + `current` symlink):

- Snipe-IT
- Monica
- Firefly III
- BookStack

### Related docs

- [VM site Laravel](VM_SITE_LARAVEL.md) — framework-specific site workspace
- [VM site deploy](VM_SITE_DEPLOY.md) — deploy strategy and hooks
- [VM site daemons](VM_SITE_DAEMONS.md) — queue workers
- [VM site cron](VM_SITE_CRON.md) — scheduler
- [Deployment flow](DEPLOYMENT_FLOW.md) — git link and webhook overview
