# Site logs

The **Logs** section tails **application and webserver logs** for this site on the server.

## Log sources

Typical streams:

- **Deploy log** — latest release output
- **Webserver access/error** — per-site vhost logs
- **PHP-FPM** or **Rails** log under the deploy path
- **Supervisor** stdout for workers

## Live tail

Refresh or follow recent lines. High-traffic access logs truncate in the UI.

## CLI alternative

Use **`dply site logs`** or SSH **`tail`** via **Server → Console** for full files.

## Related sections

- **Deploy** — full deploy transcript on failure
- **Monitor** — uptime vs error spikes in logs
- **Server → Logs** — system-wide `/var/log`
