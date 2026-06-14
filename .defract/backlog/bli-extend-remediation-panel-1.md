---
id: bli-extend-remediation-panel-1
rawText: ''
title: Extend remediation panel to cover additional deploy failure types
type: improvement
module: resources/views/livewire/sites/partials/deployments/_remediation-panel.blade.php
labels:
- starter
groomingStatus: completed
createdAt: 2026-06-14T14:27:02Z
groomedAt: 2026-06-14T14:27:02Z
promotedTaskId: task-extend-remediation-panel-to-cover-01kv38hserdg
---

The `_remediation-panel.blade.php` currently only surfaces an inline fix action for `database_connection_failed`. Extend `config/remediations.php` and the panel to handle at least one other common failure type (e.g. missing env var or composer install failure) so more deploy errors get guided recovery.
