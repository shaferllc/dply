---
id: bli-add-phase-timeline-2
rawText: ''
title: Add phase-timeline display for simple (non-atomic) deploys
type: improvement
module: resources/views/livewire/sites/partials/deployments/_phase-timeline.blade.php
labels: []
groomingStatus: completed
createdAt: 2026-06-14T14:27:02Z
groomedAt: 2026-06-14T14:27:02Z
---

The `_phase-timeline.blade.php` partial renders phase results but is only populated when atomic deploys record phase data. Simple deploys leave the timeline empty. Add a minimal fallback state so the panel is not blank for simple-strategy deployments.
