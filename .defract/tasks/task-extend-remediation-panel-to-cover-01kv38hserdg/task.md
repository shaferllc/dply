---
defract:
  id: task-extend-remediation-panel-to-cover-01kv38hserdg
  type: improvement
  status: active
  stage: scope
  phase: 0
  total_phases: 1
  priority: normal
  source: backlog
  source_id: bli-extend-remediation-panel-1
  branch_strategy: worktree
  mode: human-in-the-loop
  created_by: tshafer
  assignee: tshafer
---

## Story Brief

Promoted from backlog item `bli-extend-remediation-panel-1`.

- Module: resources/views/livewire/sites/partials/deployments/_remediation-panel.blade.php
- Labels: starter

Original paste from the builder:

> The `_remediation-panel.blade.php` currently only surfaces an inline fix action for `database_connection_failed`. Extend `config/remediations.php` and the panel to handle at least one other common failure type (e.g. missing env var or composer install failure) so more deploy errors get guided recovery.

# Extend remediation panel to cover additional deploy failure types

## What We're Building

When a deployment fails, dply can show an inline "guided recovery" panel that explains what went wrong and offers a one-click fix. Today this only appears for one specific failure (a database connection problem). This task extends that guided recovery to cover at least one more common deployment failure, so more failed deploys give the user a clear explanation and a path to fix it instead of a raw error.

## Expected Outcome

- When a deploy fails for a newly-covered reason, the user sees a plain-language explanation of what went wrong and why.
- The user gets a guided next step (an inline fix action or clear instructions) instead of having to interpret raw deploy log output.
- At least one additional common failure type (such as a missing required environment variable, or a failed dependency install step) is recognized and gets its own guided recovery.
- Failures that are not yet covered continue to behave exactly as they do today, with no regression.

## Out of Scope

- Automatically fixing failures without the user confirming the recovery action.
- Covering every possible deploy failure type — this task adds guided recovery for at least one more common case, not an exhaustive catalogue.
- Redesigning the overall look and feel of the deployment failure screen.
