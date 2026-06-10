---
title: "App logs (dply Logs)"
slug: vm-site-app-logs
category: "Sites & deploys"
order: 180
description: "Explains dply's built-in App logs destination that streams your application's own log lines off the server via a TLS drain into the site's Logs panel."
group: sites
---

# App logs (dply Logs)

**App logs** show your application's own log lines — every `Log::info()`,
`Log::error()`, etc. — streamed off your server to dply and listed on the site's
**Logs** page. It's dply's built-in log destination: no third-party account, no
extra Composer packages.

This is different from the **Viewer / Sources** tabs on the same page, which tail
log *files* on the box over SSH. App logs are *pushed by your app* to dply and
stored centrally, so you can read and filter them in the dashboard.

## How it works

```
your app  →  Log::channel('dply_realtime')->info('…')
          →  Monolog SocketHandler (TLS-encrypted TCP, line stamped with your site token)
          →  dply drain receiver  →  stored per-site  →  this App logs panel
```

Each site gets a unique routing token; dply uses it to attribute every log line
to your site. The connection is TLS-encrypted by default, so log contents are
confidential in transit.

## Enable it (3 steps)

1. **Add the channel.** On the site's **Logs** settings, add a logging channel of
   type **dply Logs**. dply fills in the endpoint and your routing token for you —
   there's nothing to copy.
2. **Deploy.** The channel is written into your generated `config/logging.php` and
   the endpoint/token land in your `.env` on the next deploy.
3. **Log to it.** Either set it as your default log channel, or add it to your
   `stack` so existing `Log::*()` calls fan out to dply as well as your local log.

Once a deploy has run and your app logs anything at or above the channel's level,
lines appear on the **Logs → App logs** panel.

## Verify it's working

Use the **Test** button next to the channel on the Logs settings. It logs a
tagged line on your server and checks that dply received it:

- **"Confirmed"** — the full round-trip works; you'll see the test line in App logs.
- **"Sent, but not yet seen"** — your app reached the endpoint, but dply's drain
  receiver isn't receiving it yet (see Troubleshooting).

## Read & filter

- **Search** matches log message text.
- **Level** filters to a single severity (`error`, `warning`, `info`, …).
- **Refresh** re-queries; **Load more** pages further back.

## Retention & limits

- Records are kept for a rolling window (default **30 days**), then pruned.
- A per-site ingest cap protects the store from a runaway logger — if your app
  logs far more than the cap in a short burst, the excess is dropped for that
  window. Log at sensible levels in production.

## Troubleshooting

- **Empty panel after deploying?** Confirm the app actually logged something at
  the channel's level, then hit **Test**. If Test says "sent, not yet seen", the
  drain receiver isn't reachable — on self-hosted/operator setups the receiver is
  a supervised process that must be running and reachable on its UDP port (see the
  operator guide, `docs/LOG_DRAIN_RECEIVER.md`).
- **Some lines missing?** Ingest is rate-limited per site, so a heavy burst can
  drop the excess for that window. Log at sensible levels in production.

## Related

- **Logs → Viewer / Sources** — live SSH tail of the log *files* on the box.
- **Server → Logs** — machine-wide system logs (syslog, PHP-FPM, fleet activity).
- **Monitor** — uptime/SSL/response-time checks and alerting.
