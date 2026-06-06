# Changelog

## [Unreleased]
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
