---
title: "sessions, less javascript, and site screens"
date: 2026-04-01
slug: "2026-04-01-sessions-and-trimming-the-js"
summary: "Eighteen commits spread across the site workspace, services, and a deliberate effort to cut JavaScript and lean harder on Livewire."
tags: [livewire, ui, sessions, tests]
published: true
---

Eighteen commits, three of which I actually labelled — "reduce js", "sessions", and "Tests". A good cross-section of the kind of day it was: part feature, part cleanup, part keeping myself honest.

The "reduce js" commit is the one I'm happiest about philosophically. dply leans Livewire-first on purpose, and every time I catch myself reaching for a pile of hand-rolled JavaScript, that's usually a sign I'm fighting the framework instead of using it. So I went through and trimmed where I could, pushing behavior back into Livewire components where it belongs. The app gets simpler and I get fewer moving parts to debug at 11pm.

"sessions" was the other meaty one — getting session handling sorted, which is the sort of thing that's invisible when it works and infuriating when it doesn't.

Beyond the named commits, a lot of today landed in the site workspace views and the services behind them. The server side got most of the early attention, so it felt good to give the *site* side some love and start bringing it up to the same standard. There were migrations and UI component tweaks threaded through, plus the obligatory test pass to keep the new stuff pinned.

## the running theme

Less code doing more. Cutting JS, leaning on Livewire, keeping the service layer doing the heavy lifting instead of the view. It's not flashy, but it's the kind of discipline that pays off every single day afterward.

Next I want to keep pushing the site workspace toward parity with the server side.
