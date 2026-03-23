# Dply

Server management and deployment platform (inspired by [Ploi](https://ploi.io)). Connect cloud providers, provision servers, and run commands over SSH.

Built with **Laravel 13**, **Laravel Cashier**, and **DigitalOcean** (with more providers to come).

## Features

- **DigitalOcean**: Connect your account with an API token, create droplets (region/size), auto-injected SSH key
- **SSH**: Run commands on servers from the dashboard; supports key-based auth
- **Existing servers**: Add any server by IP + SSH user + private key
- **Billing**: Laravel Cashier (Stripe) is installed for future subscriptions

## Requirements

- PHP 8.3+
- Composer
- Node.js & npm (for Breeze/Vite)
- SQLite (default) or MySQL/PostgreSQL

## Setup

```bash
cd dply
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Optional: add a DigitalOcean API token to `.env` for testing (or add via UI):

```env
DIGITALOCEAN_DEFAULT_IMAGE=ubuntu-24-04-x64
```

For **provisioning** (creating droplets), run a queue worker so jobs run:

```bash
php artisan queue:work
```

Then start the app:

```bash
php artisan serve
```

Visit `http://localhost:8000`, register, then:

1. **Credentials** → Add your DigitalOcean API token
2. **Servers** → Add server → Create with DigitalOcean (pick region/size) or connect an existing server with IP + SSH key

## Project structure

- `app/Models/` — `User`, `Server`, `ProviderCredential`
- `app/Services/` — `SshConnection` (phpseclib), `DigitalOceanService` (toin0u/digitalocean-v2)
- `app/Jobs/` — `ProvisionDigitalOceanDropletJob`, `PollDropletIpJob`
- Encrypted: provider credentials (API token) and per-server SSH private keys

## Security

- Store DigitalOcean tokens and SSH private keys only in the app; they are encrypted at rest (Laravel `encrypted` cast).
- Use HTTPS and secure `APP_KEY` in production.

## License

MIT.
