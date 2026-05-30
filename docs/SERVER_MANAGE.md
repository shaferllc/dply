# Manage tools

The **Manage** section installs and upgrades **mise**-managed runtimes and bundled tools on the server.

## Runtime manager

Rows for:

- **PHP**, **Node**, **Ruby**, **Python** — versioned via mise
- **Composer**, **Git**, **Redis CLI** — auto-installed on provision
- **Docker engine**, **WP-CLI** — same install/upgrade pattern

Each row: **Install**, **Enable** (mise `use`), **Upgrade**, **Uninstall**, **Reprobe**.

## Installed but not active

Runtimes present on disk but not **enabled** cannot be selected by sites until you activate them.

## Git control panel

When Git is installed, a **Git** panel exposes version and PATH sanity checks used by deploys.

## Console streaming

Long installs show spinner states, stream output, and refresh status when the job completes.

## Related sections

- **PHP** — focused PHP-FPM view
- **Docker** — engine inspector vs mise Docker CLI
- **Services** — systemd status after installs
