---
title: "two lines in certificates"
date: 2026-06-21
slug: "2026-06-21-two-lines-in-certificates"
summary: "A genuinely tiny day: two commits, two files, both in the Certificates module."
tags: [certificates, modules, bugfix]
published: true
---

Today's entry is almost a haiku. Two commits, two files, both inside the **Certificates** module. That's the whole changelog.

I don't have a heroic story to attach to it. Certificates is the module that handles SSL/TLS issuance and renewal, and something in there needed a small correction — the kind of thing you spot, fix, commit, and don't think about again. On a weekend, after the week I'd had restructuring the entire app into modules, a two-file day is a perfectly respectable way to keep the streak alive without burning out.

Part of what makes a build-in-public log honest is including days like this. The graph isn't always a wall of green squares with dramatic feature drops. Sometimes the most useful thing you do is touch two files in a corner of the codebase that needed it and then go enjoy your Sunday.

The boring upside of the modular layout, already paying off: a small fix to certificates is now obviously, physically, *in the certificates module*. I didn't have to go digging. Tomorrow, hopefully a bit more.
