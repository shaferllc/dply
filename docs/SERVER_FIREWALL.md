# Firewall

The **Firewall** section manages **UFW** (or equivalent) rules on the server host.

## Sub-tabs

Typical layout:

- **Rules** — allow/deny ports and sources
- **Templates** — org presets (SSH, HTTP, HTTPS)
- **Status** — enabled/disabled, default policies

## Common rules

dply usually ensures:

- **22** — SSH from your IPs or globally (per policy)
- **80/443** — HTTP/HTTPS for web traffic

Add database or custom app ports explicitly.

## Apply changes

Rule edits queue remote apply with `ufw` syntax validation where possible. Misrules can lock you out — keep SSH allow rules first.

## Sub-tab pattern

Uses the shared workspace tab list with **Cron**, **Daemons**, and **SSH keys**.

## Related sections

- **Security** — failed SSH attempts to block
- **SSH access graph** — who should reach port 22
- **Edge proxy** — additional published ports
