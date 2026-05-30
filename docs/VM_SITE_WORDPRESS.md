# WordPress stack

The **WordPress** section appears when dply detects **WordPress** in the repository or path.

## Common actions

- **WP-CLI** shortcuts — cache flush, search-replace, plugin updates (where enabled)
- **File permissions** — align uploads dir with web user
- **Cron** — replace pseudo-cron with system cron calling `wp-cron.php` or disable WP cron in favor of **Cron jobs**

## WP-CLI on server

Install **WP-CLI** from **Server → Manage** if commands fail with "not found".

## Database

WordPress uses MySQL/MariaDB — create DB in **Server → Databases**, set credentials in **Environment** or `wp-config.php` via deploy.

## Related sections

- **Runtime → PHP** — version WordPress supports
- **Certificates** — HTTPS for admin and cookies
- **Authentication** — staging lock separate from wp-admin login
