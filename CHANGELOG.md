# Changelog

## [Unreleased]
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
