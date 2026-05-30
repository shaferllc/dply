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

## Per-site override

Site rules stack with org defaults — more specific site routing wins for that app.

## Related sections

- **Monitor** — checks that trigger alerts
- **Deploy** — events that fire notifications
- Org **Notification preferences** — user-level opt-out
