---
title: "Intercom notifications"
slug: notifications-intercom
category: "Organization"
order: 45
description: "Covers connecting an Intercom workspace as a notification channel, choosing in-app or e-mail delivery, and routing dply events into Intercom conversations."
group: organization
---

# Intercom notifications

Add **Intercom** as a notification channel and dply will open an Intercom
conversation — in-app through the Messenger, or as an e-mail — whenever an event
you have subscribed to fires.

Intercom channels work exactly like Slack, Discord, or e-mail channels: create
one at the personal, organization, or team level, then subscribe it to events on
the servers and sites you care about.

## What you need from Intercom

Three values, all from the workspace you want messages to land in: an **access
token**, an **admin ID**, and the workspace's **region**.

### Getting the access token

Intercom issues access tokens through an *app* in the Developer Hub, even when
that app is only ever used privately by you. Creating one takes about a minute.

1. Open the **Developer Hub** at
   [app.intercom.com/a/apps/_/developer-hub](https://app.intercom.com/a/apps/_/developer-hub).
   Make sure the workspace selector at the top is on the workspace you want dply
   to post into — the token you end up with belongs to that workspace only.
2. Select an existing app, or choose **New app**, give it a name (`dply` does
   fine), and pick the workspace it belongs to.
3. Inside the app, open **Configure → Authentication**.
4. Under the permissions list, enable **Write conversations**. This is the one
   that lets the token start conversations. If you skip it, the token is still
   valid — Intercom just refuses to send, and dply reports the permission as
   missing when you send a test.
5. On that same **Configure → Authentication** page, copy the **Access Token**.
   That is the value dply wants.

The token is also shown under **Test & Publish → Your Workspaces** if you lose
track of the page.

> You do not need to publish or submit the app. An unpublished app's access token
> works against its own workspace, which is all dply needs.

### Getting the admin ID

Intercom refuses to send a message that has no sender, so dply needs the ID of a
teammate to send as.

In Intercom, go to **Settings → Teammates**, open the teammate you want messages
to come from, and copy their ID — it is the numeric ID in the page URL. Use a
teammate on the same workspace the access token came from; a mismatch is the most
common cause of the "that admin ID does not exist" error.

Anyone on the workspace works. Picking a dedicated teammate (a "dply" or "Alerts"
seat) rather than a real person keeps alert conversations out of an individual's
inbox.

### Choosing the region

Pick whichever region the workspace lives in — US, EU, or AU.

This matters more than it looks. Intercom serves EU and AU workspaces from
separate API hosts, and a token issued for one region is rejected by the others
with an error that reads exactly like a bad token. **If your token is definitely
correct and dply still reports it as rejected, check the region first.**

## Adding the channel

1. Go to **Settings → Notifications** (or an organization's or team's
   Notifications page) and choose **Add channel**.
2. Pick **Intercom** as the type and give it a label — this is what you will see
   in the subscription matrix, so name it for the destination
   ("Ops inbox", "On-call") rather than for the provider.
3. Paste the access token, pick the region, and enter the admin ID — the form
   repeats the click path above if you need it.
4. Choose how dply should find the recipient:
   - **User e-mail address** — the usual choice; matches an existing Intercom user.
   - **User ID** / **Contact ID** — when you already have the Intercom ID.
   - **E-mail address (lead or contact)** — lets Intercom resolve the address itself.
5. Choose the **message type**:
   - **In-app (Messenger)** — the message waits in the Intercom Messenger. dply
     opens the conversation immediately so it is visible without a reply first.
   - **E-mail** — Intercom sends an e-mail. This also needs a **default subject**,
     used when an alert carries no subject of its own, and a **template**
     (plain or personal).
6. Save, then hit **Send test**. A successful test means the token, region, admin
   ID, and recipient are all good — dply posts a real message, so check the
   Intercom inbox.

The access token is stored encrypted at rest, like every other channel credential.

## Routing events to it

Once the channel exists, subscribe it to events the same way as any other
channel — from a server's or site's **Notifications** tab, from the channel
matrix, or in bulk from **Settings → Notifications → Bulk assign**.

Every event in dply can route to Intercom. Alerts arrive as a short message: the
event title, its detail, and a link back into dply.

## Product notifications

Beyond operational alerts, notifications dply sends you directly — deploy
completions, provisioning failures, backup failures, credentials — will also go
to Intercom once you have a channel configured, alongside the e-mail you already
get. The Intercom copy is derived from the e-mail, so the two stay in step.

Two notifications deliberately have no Intercom leg:

- The in-app **bell** entries, because the event behind them has already been
  routed to your Intercom channel by its subscription — an Intercom copy here
  would be the same message twice.
- **Feedback report status changes**, which are in-app only by design.

## Self-hosting note

No deployment-wide setup is required: each channel carries its own credentials,
so nothing needs to be configured in `.env` for operators to connect Intercom.

`INTERCOM_API_KEY`, `INTERCOM_ADMIN_ID`, and `INTERCOM_REGION` exist only as a
fallback for code that notifies a user who has no Intercom channel of their own.
See the `intercom` block in `config/services.php`.

## Troubleshooting

| What you see | What it usually means |
| --- | --- |
| *Intercom rejected the access token…* | Wrong token, a partial copy-paste, or the right token with the wrong region selected. |
| *That Intercom access token is no longer valid…* | It was revoked or expired. Re-issue it under **Configure → Authentication**. |
| *…missing the "Write conversations" permission.* | Enable it under **Configure → Authentication**, then re-issue the token — changing permissions does not update an already-issued one. |
| *That admin ID does not exist…* | The admin ID belongs to a different workspace than the token. |
| *Intercom could not find the recipient.* | No Intercom user matches that e-mail or ID. Try **E-mail address (lead or contact)**, which lets Intercom resolve it. |
| *Intercom rejected the message parameters…* | Usually an e-mail message with no subject. Set a default subject. |
| *The Intercom plan on that workspace does not include the messages API.* | Outbound messages are not available on that Intercom plan. |
