---
title: "one commit, the whole site stack"
date: 2026-05-09
slug: "2026-05-09-site-services-and-migrations"
summary: "A single big commit across site services, models, migrations, and the site workspace — a coherent slice rather than scattered fixes."
tags: [site, services, migrations, models]
published: true
---

One commit, but a meaty one — north of a hundred files, and it cut a clean vertical line through the site stack: services, models, a database migration, the site workspace views and the Livewire components on top, plus tests and config to match.

When a single commit touches a migration *and* models *and* services *and* the UI, that's almost always one feature landing as a slice rather than a pile of unrelated edits. New schema at the bottom, models to wrap it, services to do the actual work, and a workspace surface so you can see and drive it. I like committing that way when I can — it keeps a feature's pieces together in history instead of smeared across a week.

The migration is the part I always treat with a little extra care. Schema changes are the one move that isn't trivially reversible in production, so adding a column or a table gets read twice and tested before I trust it. Having the tests in the same commit is me making sure the new shape actually holds up before it goes anywhere near a real database.

I'm being deliberately vague on the *what* because the commit message was, too — but the shape of the diff tells an honest story: a focused build on the site side, top to bottom, in one go. Onward.
