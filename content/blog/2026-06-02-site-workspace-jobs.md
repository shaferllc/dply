---
title: "a day in the site workspace"
date: 2026-06-02
slug: "2026-06-02-site-workspace-jobs"
summary: "Mostly site workspace UI and the services and jobs behind it, with model and config changes filling in the gaps."
tags: [ui, services, jobs, sites]
published: true
---

Today was a site-side day. Thirteen commits, none of them with messages worth quoting, but they clustered hard around the **site workspace views and UI** and the **services** and **jobs** that feed them.

That combination — views plus services plus queued jobs — is the dply rhythm by now. Anything that touches a real server can't run inline in a Livewire request, so the pattern is always: button in the UI, dispatch a job, poll for the result. A surprising amount of the "UI work" is actually wiring up that polling so the page feels alive while something grinds away on a box somewhere.

There was model and config movement underneath too, the kind that usually means I was adding a field or a setting that the new UI needed to hang off of.

The honest version of a day like this: it's a lot of small, careful moves rather than one big swing. No fires, which after the last couple of weeks I've learned to appreciate rather than distrust. The momentum is in the site workspace right now and I think it stays there for a bit.
