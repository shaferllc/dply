---
title: "Edge alerts"
slug: edge-alerts
category: "Edge"
order: 119
description: "Route Edge events to notification channels and set RUM / error thresholds."
group: edge
---

# Edge alerts

**Alerts** has two parts:

1. **Channels** — subscribe Slack, email, and other notification channels to Edge events (`edge.deploy.*`, domains, usage, RUM breaches), same system as BYO site notifications.
2. **Thresholds** — RUM / error caps checked hourly; breaches publish `edge.rum.breach`.

## Channel subscriptions

Expand a channel, tick the Edge events it should receive, then **Save subscriptions**. Create channels inline or under **My channels** / **Organization channels**.

| Event | When |
|-------|------|
| Deploy succeeded / failed | Publish or build failure |
| Deploy duration regressed | Build got noticeably slower |
| Domain verified / failing | Custom hostname TLS |
| Usage over budget | Guardrail trip |
| RUM breach | Threshold crossed (see below) |

## Thresholds

| Metric | Typical start |
|--------|----------------|
| LCP p75 | 2500 ms |
| 5xx error rate | 5% |
| 5xx count | 50 / hour |

Checked against the last 60 minutes, with a cooldown per kind so you are not spammed. Thresholds can also live in `dply.yaml` under `alerts:`.

## Tips

- Wire channels **before** a launch so deploy failures and RUM breaches reach someone.
- Start with **Forms-only** bot protection and conservative RUM thresholds, then tighten.
- In-app inbox still receives events for stakeholders even without a channel.

## Related sections

- **Traffic & analytics** — Core Web Vitals and live requests that feed RUM checks
- **Bot protection** / **Rate limits** — reduce noise before alerting
- Profile **Notification channels** — org-wide destinations
