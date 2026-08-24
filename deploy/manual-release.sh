#!/usr/bin/env bash
#
# Manual atomic release — the fallback for when `dply:site:deploy` cannot run.
#
# The deploy engine lives in the control plane, so when the control-plane
# database is unreachable the engine cannot deploy the fix for the thing that
# made it unreachable. This script does the same release/swap by hand, on the
# host, with no database involved until you ask for migrations.
#
# Run it ON the target host, as the deploy user:
#
#   ./manual-release.sh --root /home/dply/dplyio --role web
#   ./manual-release.sh --root /home/dply/worker-1.dply.io --role worker
#
# Layout it expects (deploy/ATOMIC_RELEASES.md):
#   <ROOT>/repo/           bare-ish checkout it fetches into
#   <ROOT>/releases/<ts>/  one directory per release
#   <ROOT>/shared/.env     sacred; symlinked into every release
#   <ROOT>/shared/storage  symlinked into every release
#   <ROOT>/current   ->    releases/<ts>
#
set -euo pipefail

ROOT=""
ROLE="web"                 # web | worker
BRANCH="main"
KEEP="${DEPLOY_KEEP_RELEASES:-5}"
RUN_MIGRATIONS=0           # opt-in: needs the database
SKIP_PREFLIGHT=0
BUILD_ASSETS=1

usage() {
    cat <<'USAGE'
Usage: manual-release.sh --root <path> [options]

  --root <path>      Deploy root (required), e.g. /home/dply/dplyio
  --role web|worker  What to restart after the swap (default: web)
  --branch <name>    Branch to deploy (default: main)
  --keep <n>         Releases to retain (default: $DEPLOY_KEEP_RELEASES or 5)
  --migrate          Run `artisan migrate --force` (needs a reachable DB)
  --no-assets        Skip `npm ci && npm run build`
  --skip-preflight   Deploy even if the database is unreachable
  --rollback         Swap `current` to the previous release and restart
  -h, --help         This text
USAGE
}

ROLLBACK=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --root) ROOT="${2:?--root needs a path}"; shift 2 ;;
        --role) ROLE="${2:?--role needs web|worker}"; shift 2 ;;
        --branch) BRANCH="${2:?--branch needs a name}"; shift 2 ;;
        --keep) KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --migrate) RUN_MIGRATIONS=1; shift ;;
        --no-assets) BUILD_ASSETS=0; shift ;;
        --skip-preflight) SKIP_PREFLIGHT=1; shift ;;
        --rollback) ROLLBACK=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ -n "$ROOT" ]] || { echo "error: --root is required" >&2; usage >&2; exit 2; }
[[ -d "$ROOT" ]] || { echo "error: $ROOT does not exist" >&2; exit 2; }
[[ "$ROLE" == "web" || "$ROLE" == "worker" ]] || { echo "error: --role must be web or worker" >&2; exit 2; }

SHARED="$ROOT/shared"
RELEASES="$ROOT/releases"
REPO="$ROOT/repo"
CURRENT="$ROOT/current"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

restart_services() {
    if [[ "$ROLE" == "worker" ]]; then
        say "Restarting workers"
        sudo supervisorctl reread >/dev/null 2>&1 || true
        sudo supervisorctl update >/dev/null 2>&1 || true
        sudo supervisorctl restart all || warn "supervisorctl restart failed — restart the workers yourself"
    else
        say "Reloading php-fpm + nginx"
        # Version-agnostic: reload whichever php*-fpm unit this box actually has.
        local fpm
        fpm="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)"
        [[ -n "$fpm" ]] && sudo systemctl reload "$fpm" || warn "no php-fpm unit found — reload it yourself"
        sudo nginx -t && sudo systemctl reload nginx || warn "nginx reload failed — check `sudo nginx -t`"
    fi
}

# ----------------------------------------------------------------- rollback --
if [[ "$ROLLBACK" == "1" ]]; then
    [[ -L "$CURRENT" ]] || die "$CURRENT is not a symlink — nothing to roll back"
    live="$(basename "$(readlink -f "$CURRENT")")"
    prev="$(ls -1dt "$RELEASES"/*/ 2>/dev/null | sed -n '2p' || true)"
    [[ -n "$prev" ]] || die "no previous release under $RELEASES"
    say "Rolling back from $live to $(basename "${prev%/}")"
    ln -sfn "${prev%/}" "$CURRENT.tmp"
    mv -Tf "$CURRENT.tmp" "$CURRENT"
    restart_services
    say "Rolled back to $(basename "${prev%/}")"
    exit 0
fi

# ---------------------------------------------------------------- preflight --
# The whole reason this script exists: a DNS failure on the database host
# surfaces as forty stack traces from unrelated scheduled commands. Say it once,
# in one line, before touching anything.
say "Preflight"

[[ -f "$SHARED/.env" ]] || die "$SHARED/.env is missing — it is sacred, do not let a deploy create one"
[[ -d "$SHARED/storage" ]] || die "$SHARED/storage is missing"
[[ -d "$REPO/.git" ]] || die "$REPO is not a git checkout"

DB_HOST="$(grep -E '^DB_HOST=' "$SHARED/.env" | tail -1 | cut -d= -f2- | tr -d '"'"'"' ')"
if [[ -n "$DB_HOST" && "$DB_HOST" != "127.0.0.1" && "$DB_HOST" != "localhost" ]]; then
    if getent hosts "$DB_HOST" >/dev/null 2>&1; then
        echo "  db host $DB_HOST resolves"
    else
        msg="DB_HOST does not resolve: $DB_HOST
  Nothing that touches the database will work — migrations, the scheduler, the
  deploy engine. If the managed cluster was deleted or renamed, fix DB_HOST in
  $SHARED/.env first.
  Re-run with --skip-preflight to ship code anyway (no --migrate)."
        [[ "$SKIP_PREFLIGHT" == "1" ]] && warn "$msg" || die "$msg"
    fi
fi

if [[ "$RUN_MIGRATIONS" == "1" && "$SKIP_PREFLIGHT" == "1" ]]; then
    die "--migrate and --skip-preflight together: refusing to migrate against a database that failed preflight"
fi

command -v php >/dev/null || die "php not on PATH"
command -v composer >/dev/null || die "composer not on PATH"

# ------------------------------------------------------------------ release --
STAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASE="$RELEASES/$STAMP"

say "Fetching $BRANCH"
git -C "$REPO" fetch --prune origin "$BRANCH"
git -C "$REPO" reset --hard "origin/$BRANCH"
SHA="$(git -C "$REPO" rev-parse --short HEAD)"
echo "  at $SHA — $(git -C "$REPO" log -1 --pretty=%s)"

say "Building release $STAMP"
mkdir -p "$RELEASE"
# Copy the tree rather than checking out again: one fetch, one source of truth.
git -C "$REPO" archive HEAD | tar -x -C "$RELEASE"

ln -sfn "$SHARED/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage"
ln -sfn "$SHARED/storage" "$RELEASE/storage"

say "composer install"
( cd "$RELEASE" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist )

if [[ "$BUILD_ASSETS" == "1" && -f "$RELEASE/package.json" ]]; then
    say "Building assets"
    ( cd "$RELEASE" && npm ci --no-audit --no-fund && npm run build )
fi

if [[ "$RUN_MIGRATIONS" == "1" ]]; then
    say "Migrating"
    ( cd "$RELEASE" && php artisan migrate --force )
fi

say "Caching config, routes, views, events"
( cd "$RELEASE" && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache )

# ---------------------------------------------------------------------- swap --
say "Swapping current -> $STAMP"
ln -sfn "$RELEASE" "$CURRENT.tmp"
mv -Tf "$CURRENT.tmp" "$CURRENT"

restart_services

# ------------------------------------------------------------------- retain --
say "Pruning old releases (keeping $KEEP)"
live="$(readlink -f "$CURRENT")"
# shellcheck disable=SC2012
ls -1dt "$RELEASES"/*/ 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    [[ "$(readlink -f "${old%/}")" == "$live" ]] && continue
    echo "  rm ${old%/}"
    rm -rf "${old%/}"
done

say "Deployed $SHA as $STAMP"
[[ "$RUN_MIGRATIONS" == "0" ]] && echo "  migrations were NOT run — pass --migrate once the database is reachable"
exit 0
