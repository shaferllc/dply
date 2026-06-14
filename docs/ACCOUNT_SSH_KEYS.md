---
title: "SSH keys"
slug: account-ssh-keys
category: "Account"
order: 600
description: "Save personal SSH public keys on your account, auto-provision them onto new servers, and deploy them to specific existing servers on demand."
group: account
---

# SSH keys

**Profile → SSH keys** is your personal key directory. Keys saved here can be added automatically to new servers you create, pushed onto BYO hosts, and deployed to specific existing servers on demand.

## At a glance

The hero tiles summarise your account:

- **Keys** — how many public keys are saved on your account.
- **Auto-deploy** — how many keys are flagged to be added to *every* new server.
- **Reachable** — how many servers these keys can currently be deployed to.

## Adding a key

Open **Add SSH key**, give the key a clear name (e.g. "Work laptop"), and paste the contents of your `.pub` file. To generate one locally, run `ssh-keygen` and copy the public half.

Enable **Always provision to new servers** if the key should be added automatically whenever you create a server. Leave it off to keep the key as **manual deploy only** — you then push it to specific servers when needed.

## Deploying to existing servers

Each saved key has a **Deploy** action that pushes it onto a chosen server's `authorized_keys` over SSH. Keys marked **Auto** are added to new servers without any further action; others are deployed on demand.

## BYO servers

Before creating a bring-your-own (BYO) server, add at least one key to your profile and (optionally) turn on auto-provision — the BYO server form expects a key it can install so the new host is reachable.

## Related

- [[server-ssh-keys]] — how a server reconciles its `authorized_keys` from profile, team, and server-scoped key sources.
