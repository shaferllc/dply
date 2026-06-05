# Changelog

## [Unreleased]
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
