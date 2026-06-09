# Cache engines

The **Caches** section installs and manages in-memory cache engines on the server — Redis, Valkey, Memcached, KeyDB, and Dragonfly.

## Engine tabs

Each engine has **Overview**, install/uninstall, and configuration sub-tabs. **Coming soon** engines show a **Soon** badge and cannot be installed until enabled platform-wide.

## Redis and Varnish

**Redis** is always available when the feature flag is on. **Varnish** integrates with the **Webserver** cache sub-tabs for Nginx/Apache/OLS.

## Install flow

Queue install from the engine panel. Output streams via console actions. After install, **Enable** activates the runtime via mise where applicable.

## Site usage

Sites reference cache hosts in env vars (`REDIS_HOST`, etc.). This section manages the daemon; **Site → Environment** points apps at it.

## Related sections

- **Webserver → Cache** — HTTP cache zones and purge
- **Services** — running cache processes
- **Databases → Basics** — cross-link to Redis
