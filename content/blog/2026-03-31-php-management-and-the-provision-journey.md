---
title: "php management and the provision journey"
date: 2026-03-31
slug: "2026-03-31-php-management-and-the-provision-journey"
summary: "Logs landed, server and site PHP management shipped, and I spent the day untangling the provisioning journey flow and task tracking."
tags: [php, provisioning, logs, servers]
published: true
---

Nineteen commits today, and unusually, several of them actually say what they did. Productive, slightly chaotic, very fun.

The headline is `feat(php): add server and site PHP management`. PHP version juggling is one of those things every server tool has to get right, and it's fiddly because it lives at both the server level and the site level. Getting that managed cleanly from the workspace UI felt like a proper milestone.

I also got to write the commit message I'd been waiting for: **"logs are done."** Server logs are now a real surface instead of a TODO, which makes the workspace feel a lot more trustworthy — you can actually see what a box is doing.

The other big chunk was the provisioning journey. There's a commit literally named `checkpoint before checking out cursor/provision-journey-fixes`, which tells you how the day went: I hit a rough edge in the journey flow, checkpointed, and went off to fix it. `fix(provision): improve journey flow and task tracking` was the result — better step tracking so you can follow a provision from start to finish without guessing whether it's stuck.

## what bit me

The provisioning journey is deceptively hard. It's a long async sequence, and making it *legible* — showing the user honest progress without lying about what's done — is more work than the actual provisioning. Task tracking is the unglamorous backbone of that, and it ate a good slice of the afternoon.

There's a commit just called "finish," which is optimistic of past-me. It's never quite finished. But PHP and logs being real today moved the needle a lot.
