---
title: "plumbing day: services, models, a migration or two"
date: 2026-05-31
slug: "2026-05-31-services-and-migrations"
summary: "Heads-down in the services layer with some model and migration work behind the server workspace, nothing flashy on the surface."
tags: [services, models, migrations, tests]
published: true
---

Quieter day than yesterday and more focused. Eight commits, no real commit messages to speak of, but the shape of it is clear from where the changes landed: **services**, **models**, and a couple of **database migrations**, with the **server workspace** as the front end for all of it.

When migrations show up alongside model and service changes, it's almost always because I needed somewhere new to put state — a column, a flag, a relationship — and then had to teach the service and the UI to read and write it. That's the loop I was in most of the day.

There was some config and Livewire work too, and the usual trail of tests behind it. Nothing broke loudly enough to remember, which I'll take as a small win.

The thing I keep reminding myself: a migration is a one-way door in production, so I'd rather spend the extra hour now getting the column right than spend a weekend later writing a backfill. Slow is smooth.

More of the same energy tomorrow, probably, but I can feel a feature wanting to surface.
