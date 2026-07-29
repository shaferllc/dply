---
title: "Edge forms"
slug: edge-forms
category: "Edge"
order: 112
description: "Accept HTML form POSTs on an Edge path and email the fields — no app server required. Optional honeypot and bot check."
group: edge
---

# Edge forms

**Forms** turns a path on your Edge site into a mail-backed endpoint. Visitors POST; Dply emails you the fields. No app server or serverless function is required.

Requires **Dply-hosted Edge delivery**.

## What it does

Each endpoint has:

| Field | Purpose |
|-------|---------|
| **Path** | URL path that accepts `POST` (e.g. `/api/contact`) |
| **Email to** | Inbox that receives the submission |
| **Honeypot field** | Hidden input name; bots that fill it are discarded |
| **Require bot check** | When on, submissions need a valid bot-protection token |

## How to use it

1. Open **Forms**, enable Edge forms, and add an endpoint (path + email).
2. In your HTML:

```html
<form method="POST" action="https://your-site.on-dply.site/api/contact">
  <input type="text" name="name" required />
  <input type="email" name="email" required />
  <!-- Honeypot: leave empty; hide with CSS -->
  <input type="text" name="company" tabindex="-1" autocomplete="off" style="display:none" />
  <button type="submit">Send</button>
</form>
```

3. Match the honeypot `name` to the dashboard field (default `company`).
4. Optional: enable **Bot protection**, turn on **Require bot check**, and include the challenge token field your widget provides (often `cf-turnstile-response`).
5. **Save**, then test a real POST from the live hostname.

## Tips

- Use one endpoint per form. Remove unused endpoints so they stop accepting mail.
- Paths apply after Save republishes delivery — no full rebuild required.
- Spam still happens; honeypot + bot check is defense in depth, not a guarantee.

## Related sections

- **Bot protection** — challenge keys and modes
- **Rate limits** — cap abuse on form paths (e.g. `/api/contact`)
