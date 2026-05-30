# Rails stack

The **Rails** section appears when dply detects a **Rails** app. It exposes Ruby/Rails-specific deploy helpers.

## Common actions

- **`bundle exec rails db:migrate`** — run migrations
- **Asset precompile** — hook for production builds
- **Restart** — Passenger/Puma restart via deploy hook or daemon reload

## Ruby runtime

Ensure **Server → Manage** or **Runtime → Ruby** matches the app's `.ruby-version` or `Gemfile` constraint.

## Environment

Set **`RAILS_ENV=production`**, **`SECRET_KEY_BASE`**, and database URLs in **Environment**.

## Related sections

- **Runtime → Ruby** — Bundler and Ruby version
- **Deploy hooks** — `assets:precompile`, `db:migrate`
- **Daemons** — Puma/Passenger Supervisor programs
