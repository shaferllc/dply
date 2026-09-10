---
paths:
  - 'app/Jobs/**'
---

# Jobs

## ConsoleEmitter writes output only — the job must write console_actions.status
`ConsoleEmitter` appends lines to `console_actions.output` and nothing else. It never touches `status`, `started_at` or `finished_at`.

A job that streams progress with `new ConsoleEmitter($id)` must itself:
- flip the row to `running` at the top of `handle()`,
- write `completed`/`failed` on EVERY exit — including each early `return` after an error line,
- add `failed(\Throwable $e)` so an uncaught throw or the job timeout still closes it.

Skip any of these and the row stays `queued`, so `ConsoleAction::isQueuedStalled()` fires at 45s and the banner says "no queue worker picked up this task" about work that already succeeded. This has now bitten three jobs (RunSiteQueueCanaryJob, SetUpSiteReverbJob, SetUpSiteQueueingJob) — copy their private `fail()`/`succeed()`/`complete()` helpers, or use the `WritesConsoleAction` trait when the job has a Model subject available up front.
