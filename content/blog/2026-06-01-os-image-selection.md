---
title: "pick your OS when you spin up a box"
date: 2026-06-01
slug: "2026-06-01-os-image-selection"
summary: "Added OS image selection (Ubuntu/Debian) to the provider VM create flow, plus the usual merge-day cleanup."
tags: [provisioning, servers, ui]
published: true
---

Actual visible feature today: you can now choose your **OS image** — Ubuntu or Debian — when you create a provider VM. Up to now dply quietly assumed one baseline for everyone, which is fine until someone has a strong opinion about their distro, and people always have strong opinions about their distro.

The work was mostly in the **server workspace** create path and the **jobs** that kick off provisioning. The image choice has to thread all the way from the wizard down into the provider call and then back into how we provision the box, so it touched more than you'd guess for a dropdown.

There was also an "auto stash before merge" commit in there, which is git's polite way of telling you that you had uncommitted work when you pulled `main`. No drama, just the cost of moving between machines.

## what's actually behind the dropdown

- New OS image field on the VM create flow.
- Provisioning jobs now respect the selected image instead of a hardcoded baseline.
- The usual config and model bookkeeping to carry the choice through.

The trick with this kind of thing is keeping the menu honest — only offering images we've actually tested provisioning against. A dropdown that lets you pick a broken path is worse than no dropdown. Next I want to make sure the provision scripts are equally happy on Debian as they are on Ubuntu.
