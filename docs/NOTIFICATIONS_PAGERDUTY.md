---
title: "PagerDuty notifications"
slug: notifications-pagerduty
category: "Organization"
order: 46
description: "Covers connecting a PagerDuty service as a notification channel, which events page on-call, how incidents deduplicate, and when dply resolves them automatically."
group: organization
---

# PagerDuty notifications

Add **PagerDuty** as a notification channel and dply will raise incidents against
a PagerDuty service — waking whoever that service's escalation policy says to
wake.

PagerDuty is treated differently from the chat-shaped channels on purpose. Slack,
Discord, and Intercom deliver messages; PagerDuty pages a human at 3am. So dply
is conservative about what reaches it, and deliberate about closing incidents
again.

## Getting the integration key

The credential is an **Events API v2 integration key**. It belongs to one
PagerDuty service, which means choosing the key *is* choosing what gets paged.

1. In PagerDuty, open the service you want dply to page:
   **Services → Service Directory →** your service.
2. Open the **Integrations** tab and choose **Add an integration**.
3. Pick **Events API v2**.
4. Copy the **Integration Key** it generates.

> It must be **Events API v2**. A v1 key looks similar but takes a different
> payload shape, and dply's events will be rejected.

### Region

Pick **US** or **EU** to match your PagerDuty account.

A key from the wrong region fails with a `400` whose message names the *routing
key* — so it reads like a bad key rather than a wrong region. If the key is
definitely right, check the region.

## Adding the channel

1. **Settings → Notifications** (personal, organization, or team) → **Add channel**.
2. Type **PagerDuty**, label it for the service it pages ("Platform on-call"),
   and paste the integration key.
3. Optionally set **component** and **group** — these show on the incident and
   are useful if one PagerDuty service receives alerts from several sources.
4. **Default severity** is only used for alerts that arrive without one. Events
   routed through the subscription matrix bring their own.
5. Hit **Send test**. The test deliberately raises an **info**-level incident, so
   it proves the wiring without paging anyone.

The integration key is stored encrypted at rest.

## What actually pages

Two separate paths reach PagerDuty, and they behave differently.

### Events you subscribe to

Anything you attach the channel to in the subscription matrix. These carry their
own severity, mapped onto PagerDuty's scale:

| dply severity | PagerDuty |
| --- | --- |
| critical, fatal | `critical` |
| error, danger, failure | `error` |
| warning | `warning` |
| anything else | `info` |

An unrecognised severity becomes `info`, never `critical` — a mapping gap should
not invent an emergency.

### Notifications dply sends you directly

These are **opt-in per notification**, and most do not page at all. Only these
nine raise incidents:

| Notification | Severity | Pages when |
| --- | --- | --- |
| Server provision failed | critical | Always (auto-retry is already exhausted) |
| Webserver health alert | critical / warning | Threshold tripped — and **resolves** on recovery |
| Backup failed | error | Not for test alerts |
| Supervisor programs unhealthy | error | Always |
| Cron job alert | error | Only on a non-zero exit |
| Site deployment | error | Only when the deploy failed |
| Snapshot status | error | Only when the snapshot failed |
| SSH key rotation due | warning | Always |
| Quick download failed | warning | Not when the cause is an over-cap quota |

Everything else — credentials, invitations, "download ready", "server
provisioned" — never pages, whatever channels you have configured. That is the
default: a notification stays silent unless someone deliberately opted it in.

## Deduplication

Repeated alerts about the same thing update **one** incident instead of opening a
new one each time. dply derives a stable deduplication key per condition, so a
server that flaps twenty times produces one incident, not twenty.

The key is built from the resource and the event — never from the individual
event's ID, which would defeat the point.

## Automatic resolution

**Webserver health alerts resolve themselves.** That check is edge-triggered on
state transitions, so when a metric drops back under its threshold dply sends a
PagerDuty *resolve* against the same deduplication key and the incident closes.
Nobody has to close it by hand.

No other notification auto-resolves, because nothing else tells dply that a
condition has cleared. Those incidents are yours to close.

## Self-hosting note

No deployment-wide setup is required — each channel carries its own integration
key.

`PAGERDUTY_ROUTING_KEY` and `PAGERDUTY_REGION` exist only as a fallback for code
that notifies a user with no PagerDuty channel of their own. See the `pagerduty`
block in `config/services.php`.

## Troubleshooting

| What you see | What it usually means |
| --- | --- |
| *PagerDuty did not recognise the integration key.* | Partial copy-paste, a v1 key instead of v2, or the right key with the wrong region. |
| *PagerDuty refused the request…* | The integration was disabled on the PagerDuty service. |
| *PagerDuty is rate limiting us.* | Too many events too quickly; they will settle. |
| *…rejected the severity.* | Only critical, error, warning, and info are valid. |
| *…rejected as expired. Check this server's clock.* | The event timestamp was too far from PagerDuty's clock — usually NTP drift on the dply host. |
| Test worked, real alerts don't arrive | The channel is not subscribed to any events yet, or the notification you expected is one of the ones that deliberately never pages. |
