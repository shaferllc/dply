---
title: "more mcp, mail test-sends, and borrowing tooling from saved repos"
date: 2026-06-10
slug: "2026-06-10-mcp-mail-and-borrowed-tooling"
summary: "A scattered day across MCP, mail provider test-sends, multi-site deploy, and warning people about broken .env files before they bite."
tags: [mcp, mail, deploys, hygiene]
published: true
---

One of those days where the commit log reads like a grocery list, because that's what it was. No single big build — just a lot of edges getting sanded down across docs, services, and packages.

A few things I can actually name:

- **Mail test-send** is wired up for SendGrid and Cloudflare now. Configuring a mail provider and then *hoping* it works is a bad experience; you should be able to push the button and get the email.
- More **MCP** surface. The remote MCP server keeps growing as I expose more of the dply API through it, and today added another chunk.
- **Multi-site deploy** got some attention, along with fixing server syncs that were drifting.
- A nice quality-of-life one: **borrowing log / nginx / cron tooling from saved repos**, so the patterns you've already got don't have to be reinvented per site.

The one I think users will actually feel is "**warn of bad .env**." A malformed env file is one of those failures that's invisible until something downstream explodes in a confusing way. Catching it early and saying "hey, this is broken" up front saves a debugging session that would otherwise start three steps too late.

## the annoying part

"Rotung resources" is a real commit message from today and no, that is not a typo I'm proud of. Also did a "fix deleted" pass, which is exactly as glamorous as it sounds — cleaning up after something that got removed and left dangling references.

Realtime got a touch too. Mostly this was a connective-tissue day: nothing you'd put on a landing page, all of it the stuff that makes the landing-page features not embarrassing.
