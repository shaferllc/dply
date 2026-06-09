# Git hooks (pre-deploy generators)

Versioned, **opt-in** git hooks that run pre-deploy generators before a push
reaches the remote. Currently: the AI **changelog** entry and the AI **roadmap**
refresh.

## Enable (per clone)

```bash
git config core.hooksPath githooks
```

That's it — `git push` now runs `githooks/pre-push`. Disable with
`git config --unset core.hooksPath`.

## What runs

`githooks/pre-push` executes every `*.sh` in `githooks/pre-push.d/` in order,
passing:

- `DPLY_PUSH_RANGE` — the commit range being pushed (`<remote_sha>..<local_sha>`,
  or the lone sha for a new branch)
- `DPLY_PUSH_BRANCH` — the target branch

| Script | Does | Commits? |
|--------|------|----------|
| `10-changelog.sh` | `claude`-generated entry → `changelog.blade.php` + `CHANGELOG.md` | files written; orchestrator commits + re-pushes |
| `20-roadmap.sh` | `dply:roadmap:ai-update --sync` (writes DB) | no |

If `10-changelog.sh` produces changes, `pre-push` commits **only** the changelog
files and re-pushes `HEAD` (guarded by `DPLY_GITHOOK_SKIP` so it doesn't recurse),
so the changelog ships with the same `git push` — no second run.

## Notes

- Requires the `claude` CLI on PATH (changelog) and a bootable app (roadmap).
- Both generators are best-effort and never block a push on their own failure
  (only a hard error in a hook aborts).
- Add a generator by dropping an executable `NN-name.sh` into `pre-push.d/`.
- This is independent of deployment — deploys are moving to dply-self-deploy
  (see `docs/SELF_DEPLOY.md`); these hooks just keep changelog/roadmap fresh.
