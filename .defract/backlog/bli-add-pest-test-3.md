---
id: bli-add-pest-test-3
rawText: ''
title: Add Pest test for SiteEnvValidator danger gate
type: task
module: tests/Unit
labels: []
groomingStatus: completed
createdAt: 2026-06-14T14:27:02Z
groomedAt: 2026-06-14T14:27:02Z
---

The env pre-write danger gate (static validator that blocks pushes containing dangerous values) is business-critical but has thin direct test coverage. A focused Pest unit test would catch regressions before they reach prod.
