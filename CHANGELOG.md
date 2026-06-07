# Changelog

## [Unreleased]
### Changed
- The Server Logs ClickHouse client now verifies HTTPS connections against the supplied private CA certificate, securing cross-provider log queries to the managed log store.
### Fixed
- Log agent installs no longer get stuck on "installing" and now report a clear failure when the server isn't a reachable VM host.
### Added
- Servers can now ship all host logs to a managed ClickHouse store via an installable Vector agent with a native log explorer, plus scheduler runs capture and retain their output history.
### Changed
- Supervisor install, sync, and restart now run as background jobs with directory pre-flight checks, a worker backend status check, and paginated cron/daemon history.
### Fixed
- Warm-pool servers that silently stall during provisioning are now detected and recovered automatically, so new servers finish setup reliably.
### Added
- Managed servers can now be claimed instantly from a pre-provisioned warm pool instead of waiting for a cold provision.
### Changed
- Server provisioning can now download language runtimes in the background and prefetch stock packages in parallel to shorten setup time, and the server-removal flow no longer flashes a spurious 404 modal.
### Changed
- Server provisioning jobs now run on a dedicated priority queue and MySQL readiness is detected faster, so new servers come online sooner.
### Changed
- Servers can warm up apt packages at boot and defer certbot off the provisioning critical path, with too-small sizing now a warning instead of a hard block.
### Added
- New Hetzner servers can launch from a pre-baked base image, cutting provisioning time, with refreshed loading states across the server workspace.
### Changed
- New servers launch from region-scoped baked snapshots when available and poll for their IP address faster, cutting provisioning time.
### Added
- Wedged server provisions now surface and recover automatically when a remote task goes silent, and machine callbacks keep working during maintenance windows.
### Fixed
- Server provisioning no longer stalls when machine callbacks hit the coming-soon gate or when an optional PHP extension is unavailable in the configured apt repositories.
### Changed
- Standardized button and icon styling across the dashboard and added a site binding catalog powering site settings navigation.
### Changed
- Button components now render as links when given an href, and attaching a redis-driver queue, cache, or session binding now requires a Redis resource first.
### Changed
- Server schedule and services screens now use shared button components for consistent styling across actions.
### Added
- The realtime broadcasting relay now records connection, subscription, and publish events with per-message delivery counts for easier monitoring.
### Added
- Site logs can now stream live into an in-app App Logs panel via the dply Realtime drain, with one-command Cloudflare relay setup.
### Added
- Sites can now define their complete logging setup—channels, default, stack, and deprecations—which dply generates and owns in config/logging.php on the next deploy.
### Added
- Sites can now configure mail and log drain resources with per-provider credentials and server-side test email delivery, alongside tiered realtime apps.
### Changed
- The site environment settings page has been reorganized internally for faster loading and easier maintenance, with no change to available options.
### Added
- Connecting object storage to a site now offers AWS S3, DigitalOcean Spaces, and Hetzner provider presets with region pickers that auto-derive the endpoint, plus a custom S3 option.
### Added
- You can now bind a cache store (database, redis, file, or array) to a site, and freshly provisioned servers ship the phpredis, GD, sodium, GMP, APCu, igbinary, and SQLite PHP extensions out of the box.
### Added
- Operators can now temporarily bypass the branded error page to surface real 5xx errors when debugging a failing site.
### Added
- The deploy panel now shows a "scanning the repo" placeholder while a pipeline suggestion scan is running instead of flashing stale suggestions.
### Fixed
- The "Optimize pipeline" action now clears the pipeline-check warning once steps are added and shows the proposed-changes preview when the repo scan finishes, instead of appearing to do nothing.
### Fixed
- Deploy steps that run both Composer and npm now reliably find both tools instead of failing with "npm: command not found".
### Fixed
- Direct links with a pipeline_tab query parameter now open the correct pipeline sub-tab when the deploy pipeline is embedded in another page.
### Fixed
- Fixed environment file pushes failing to set correct ownership due to shell quoting that corrupted the chown user argument.
### Fixed
- Deploys no longer fail to prune old releases when root-owned files (from certbot or managed error pages) are present.
### Fixed
- Deploys of private HTTPS repositories now authenticate correctly on re-deploy by passing the token-injected URL directly to git instead of relying on a stored remote, while keeping credentials out of the server's git config.
### Fixed
- HTTPS repository clones now authenticate correctly even when the git provider isn't explicitly set, by detecting it from the repository URL, and env files are written with the correct site-user ownership.
### Fixed
- Deploys from private HTTPS repositories now authenticate automatically using your stored Git provider token, with tokens redacted from deploy logs.
### Changed
- Deploy logs now include detailed pre-clone, post-clone, and phase-probe snapshots, plus a "Scan for required variables" action in site environment settings.
### Fixed
- The open-error count badge no longer appears on the server Errors tab while it is still a coming-soon preview.
### Fixed
- Feature flag values are now cleared on each deploy so flag changes take effect immediately after release.
### Changed
- The browser-based server console is now shown as a coming-soon preview while the full console feature is gated off.
### Changed
- The site CLI settings tab now shows a coming-soon preview of upcoming terminal commands when the feature is not yet available for your server.
### Added
- Site settings now include an in-browser CLI console for running dply commands against your site with quick-run shortcuts for common operations.
### Added
- You can now create and link a database directly from a VM site's page, manage workers, schedules, and basic auth via a new site resource API.
### Fixed
- Worker deploys now restart dply-managed systemd Horizon and scheduler units on each release swap so daemons no longer run stale code, with legacy supervisor restarts kept as a fallback.
### Fixed
- Deployments now clone the bare repository using the server's own authenticated remote URL, avoiding failures when the local remote uses an SSH URL the server can't access.
### Changed
- Deploys now build immutable releases and flip an atomic current symlink across web and worker hosts, preventing long-running workers from serving stale code and breaking queued-job deserialization.
### Fixed
- Corrected a Blade templating error that could prevent the pre-flight job console from rendering during site setup.
### Fixed
- Git provider identity lookups are now memoized per request, reducing duplicate database queries when rendering site source-control views.
### Fixed
- Repository and commit listings no longer error out when a stored Git token can't be decrypted, and duplicate identity lookups during a page render are now cached.
### Fixed
- Fixed a crash when loading a site with a stale setup tab link after first-deploy setup had already completed.
### Added
- The site setup wizard now shows a live console streaming the pre-flight job's progress and the exact reason it stalls or fails.
### Changed
- The repository picker now supports arrow-key navigation and Enter-to-select, and the ⌘K command palette is available on marketing pages while signed in.
### Changed
- The Git repository picker now behaves consistently across the choose-app, custom-site create, and repository connection flows.
### Added
- Repository commit views now show a dismissible notice when a missing configured branch falls back to the repo's default branch.
### Fixed
- Repository commit views now fall back to the repository's default branch with a notice when the configured branch no longer exists, instead of showing an error.
### Added
- The repository commits view now shows a retry button when commits fail to load and displays which linked account answered the read, with a quick link to change it.
### Added
- The repository overview now shows which linked Git account answered each read and persists the account choice immediately so commits, branches, and files resolve to the selected identity.
### Changed
- The repository URL input now uses the shared text input component for consistent styling.
### Changed
- The linked source-control account dropdown now uses the standard styled select component for a consistent appearance.
### Fixed
- Repository commits and README error states now offer a Retry button to re-fetch the data without reloading the page.
### Fixed
- A site setup pre-flight scan that stalls now shows a manual re-scan button so you can unstick the wizard and proceed to deploy.
### Fixed
- Server remote-access tracking no longer errors when a stale release leaves a queued job's command class unresolved.
### Fixed
- Supervisor program configs now install correctly under non-root SSH users and resolve their working directory from the attached site, preventing silent install failures and stale imported paths.
### Changed
- The clone-server action is now available on both the Manage and Configuration workspaces, and single-daemon re-sync reports whether the program actually started running or failed with a reason.
### Added
- Cron jobs gain a one-click library of common Laravel artisan and generic command presets, and daemon programs missing from Supervisor can now be re-registered on the server with a new Sync action.
### Added
- Adds a CLI tab for installing and managing servers from your terminal, and lets you remove bundled firewall templates with per-rule status while always preserving the SSH lifeline rule.
### Added
- You can now re-query DigitalOcean and Hetzner for a server's private IP from the connection settings, with live certificate scans now timing out gracefully instead of spinning forever.
### Added
- The sites list now supports search, status filtering, sorting, and a summary stat strip, with dashboard cards linking through to servers and fleet health.
### Changed
- The Realtime coming-soon page now shows a richer preview with a terminal demo and feature highlights, and the Deploy sync entry is hidden from navigation.
### Added
- The Pulse dashboard now shows dedicated cards for Redis, database, and worker servers with live CPU, memory, and disk metrics, including those infrastructure hosts that don't run the dply app.
### Fixed
- Fixed a grammatical typo in the empty-state message shown when no database backup schedules exist.
### Added
- The Realtime page now shows a preview of the managed Pusher-compatible WebSocket relay when the feature isn't yet enabled for your organization.
### Removed
- Removed the unused Reverb health check link from the admin Operations dashboard.
### Added
- The Backups page now shows backup health metrics, schedules you can pause or run on demand, recent runs, and storage destinations when the feature is enabled.
### Changed
- The Backups section now appears under the main Browse menu as a coming-soon feature, and server-side broadcast events are correctly proxied to Reverb over the site's vhost.
### Added
- The features page now showcases Edge and Cloud hosting—container apps, serverless functions, managed realtime, and CDN storage—alongside the new PHP CLI and worker-pool details.
### Added
- Deploys now publish titled entries to the public changelog page alongside CHANGELOG.md.
### Added
- TLS sites now 301-redirect HTTP to HTTPS while still serving ACME challenges, and deploys auto-generate commit messages and changelog entries.
