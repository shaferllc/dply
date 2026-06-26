---
title: "a maintenance page and notification-channel test emails"
date: 2026-06-15
slug: "2026-06-15-maintenance-page-and-mail-tests"
summary: "Built a proper maintenance page, queued notification-channel test emails, and fixed a Cloudflare mail key mapping bug."
tags: [maintenance, mail, notifications, servers]
published: true
---

Today's theme, loosely, was "let people confirm things work before they need them to."

The bigger piece was a **maintenance page** — a real one. When a site goes into maintenance, you want a deliberate, decent-looking page, not a raw 503 or whatever the webserver coughs up by default. So now there's an actual surface for it.

The other thread was notifications. I made the **notification-channel test email queued** rather than inline, which matters more than it sounds: sending mail synchronously from a request is a great way to make a page hang while it waits on an SMTP handshake. Queue it, poll it, done. And I fixed a **Cloudflare mail key mapping** bug — the kind where the config key you set and the key the code reads have drifted apart, so your perfectly-correct settings quietly do nothing. Those are maddening precisely because nothing errors; it just silently doesn't send.

There was also a "tools" pass, mostly tightening up the server workspace.

## what bit me

The Cloudflare key mapping. I keep relearning that "I configured it correctly and it doesn't work" is, nine times out of ten, a naming mismatch somewhere in the plumbing — not a logic bug. The fix is two characters; finding it is an hour. The test-send button I built today exists partly so the *next* version of this bug gets caught in five seconds instead of five emails-that-never-arrived.

Lots of churn across server views, jobs, and config. Smaller day than yesterday, but every piece of it was about reducing the gap between "I set it up" and "I know it works."
