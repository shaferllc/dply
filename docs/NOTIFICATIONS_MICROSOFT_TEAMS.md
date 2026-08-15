---
title: "Microsoft Teams notifications"
slug: notifications-microsoft-teams
category: "Organization"
order: 47
description: "Covers posting dply alerts into a Teams channel with a Power Automate Workflows webhook, and why the older Incoming Webhook connector no longer works."
group: organization
---

# Microsoft Teams notifications

Add **Microsoft Teams** as a notification channel and dply posts alerts into a
Teams channel as an Adaptive Card — title, detail, and a button back into dply.

## Read this before following any other Teams guide

Most Teams webhook instructions on the internet — including Microsoft's own
older pages — tell you to add an **Incoming Webhook** connector. That no longer
works.

Microsoft retired Office 365 connectors between **18 and 22 May 2026**. Connector
URLs (`https://…webhook.office.com/…`) stopped delivering. dply detects those
URLs and refuses them rather than accepting a channel that would silently never
fire.

What you want instead is a **Power Automate Workflow**.

## Creating the workflow

1. In Teams, find the channel you want alerts in. Click the **…** menu next to it
   and choose **Workflows**.
2. Pick the template **"Post to a channel when a webhook request is received"**.
3. Sign in if prompted, then confirm the team and channel.
4. Create the flow. At the end it shows you a URL — copy it.

The URL looks like
`https://prod-00.westus.logic.azure.com:443/workflows/…`. The host will contain
`logic.azure.com`; if yours contains `webhook.office.com`, you followed the old
connector flow and need to start again at step 1.

## Adding the channel

1. **Settings → Notifications** (personal, organization, or team) → **Add channel**.
2. Choose **Microsoft Teams** and paste the workflow URL. The form tells you
   immediately whether the URL looks like a workflow or a retired connector.
3. Label it for the destination — "Platform alerts", "#ops" — rather than for the
   provider.
4. **Send test**. A card should appear in the Teams channel within a few seconds.

There is nothing to configure on the dply deployment: the workflow URL already
encodes which team and channel the card lands in, so it is the whole credential.

## What arrives

Alerts render as an Adaptive Card:

- The event title as a bold heading
- The detail as body text
- An **Open in Dply** button linking back to the affected server or site

Notifications dply sends you directly — deploy results, provisioning failures,
backup failures, credentials — also post to Teams once a channel exists,
alongside the email you already get. The card content is derived from the email,
so the two stay in step.

Two notifications deliberately have no Teams leg: the in-app bell entries (the
event behind them has already been routed to your Teams channel by its own
subscription, so a second copy would be a duplicate) and feedback report status
changes, which are in-app only by design.

## Routing events to it

Subscribe the channel the same way as any other — from a server's or site's
**Notifications** tab, from the channel matrix, or in bulk from
**Settings → Notifications → Bulk assign**. A channel with no subscriptions
delivers nothing; the channel list marks those **Not routed**.

## Troubleshooting

| What you see | What it usually means |
| --- | --- |
| *That is an Office 365 connector URL…* | You followed an Incoming Webhook guide. Create a Workflow instead, per the steps above. |
| *That workflow no longer exists.* | The flow was deleted in Power Automate. |
| *Power Automate refused the request…* | The flow is turned off, or its trigger was changed away from "When a Teams webhook request is received". |
| *The workflow URL signature is invalid or expired.* | Copy a fresh URL from the flow trigger — regenerating the trigger changes the signature. |
| *Power Automate is rate limiting us.* | Too many events too quickly; they will settle. |
| Test reports success but nothing appears in Teams | Check the flow's run history in Power Automate — the webhook accepted the request but a later step in the flow failed. |
