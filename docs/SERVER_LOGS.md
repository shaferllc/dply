# System logs

The **Logs** section tails allowlisted **system log files** on the server over SSH.

## Log picker

Choose from curated paths:

- **auth.log** — SSH and sudo events
- **syslog** — general system messages
- **nginx/error.log** — webserver errors (engine-dependent)
- **php-fpm** logs — pool errors

Paths adapt to installed stack tags.

## Live tail

Stream recent lines with refresh. Very active logs truncate to keep the panel responsive.

## Secrets

Log lines may contain tokens or PII — do not share raw tails externally. dply does not persist full log content in the control plane.

## Related sections

- **Security** — auth.log digest
- **Site → Logs** — application logs under the deploy path
- **Console** — ad-hoc `tail` and `journalctl`
