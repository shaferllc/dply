---
title: "Site notifications"
slug: vm-site-notifications
category: "Sites & deploys"
order: 200
description: "Covers routing deploy and monitor events for a site to org notification channels like Slack, email, and webhooks, with per-site overrides."
group: sites
---

# Site notifications

The **Notifications** section routes **deploy and monitor events** for this site to org notification channels.

## Events

Subscribe channels to:

- **Deploy finished** — success or failure
- **Deploy started** — optional
- **Monitor down/up** — uptime check state changes

Org-level **deploy-finish email** can be disabled separately from integration webhooks.

## Channels

Pick from org-configured **Slack**, **email**, **webhook**, and other channels. Add channels in org **Notifications** settings.

### Connecting Slack

Use **Add to Slack** in **Notifications** settings to approve dply for your workspace once. After that, every Slack channel you add is a dropdown pick — no webhook URLs to copy. One connection covers the whole workspace, and you can connect more than one workspace.

- **Public channels** work immediately.
- **Private channels** are listed but need dply invited first: run `/invite @dply` in the channel.
- **Disconnecting** a workspace leaves its channels in place; they stop delivering until you reconnect.

Pasting an **incoming webhook URL** still works and is the only option on self-hosted installs that have not registered a Slack app.

### Connecting Discord

**Add to Discord** in **Notifications** settings adds the dply bot to your server. After that, channels are a dropdown pick, and one connection covers every channel in the server.

- dply asks for only two permissions: **View Channels** and **Send Messages**.
- Only **text** and **announcement** channels appear — voice channels and categories can't receive messages.
- A channel with restricted permissions needs the **dply role** allowed to view and post in it. Server-wide permission does not override a channel-specific denial, and it shows up as a failed send rather than a missing channel.
- **Disconnecting** removes the server from dply but does not remove the bot from Discord — kick the bot there to fully revoke access.

Pasting a **webhook URL** still works and is the only option on self-hosted installs without a registered Discord application.

### Connecting Telegram

Telegram has no OAuth, so the flow is slightly different: **Connect Telegram** in the channel form opens Telegram with a group picker. Pick a group, and the dply bot joins it — the form is watching and fills itself in when the connection lands. You never leave the page or copy a chat ID.

- Works for **groups** (the picker) and for a **direct message** to yourself (the "send to me directly" link).
- **Channels** need the dply bot promoted to **administrator** before it can post.
- Connect links are **single-use and expire after 15 minutes**. Treat one like a password until it's used — anyone holding it could attach their own chat to your account.
- **Disconnecting** removes the chat from dply but does not remove the bot from Telegram — remove it there to fully revoke access.

Self-hosted note: this needs `TELEGRAM_BOT_TOKEN` plus a registered webhook (`php artisan telegram:set-webhook`, and `--show` to debug delivery). Telegram only pushes updates to a public HTTPS URL, so without the webhook nothing arrives and Connect will appear to hang. Pasting a **bot token and chat ID** by hand remains available and needs none of that.

## Per-site override

Site rules stack with org defaults — more specific site routing wins for that app.

## Related sections

- **Monitor** — checks that trigger alerts
- **Deploy** — events that fire notifications
- Org **Notification preferences** — user-level opt-out
