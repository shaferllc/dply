<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Jobs\Concerns\BuildsProvisionScriptPreamble;

/**
 * The single implementation of "drop an apt source that can never verify."
 *
 * Two callers need the same policy and must not drift: the provision preamble
 * ({@see BuildsProvisionScriptPreamble}) prunes mid-run so a
 * dead repo cannot abort a later step, and the day-two repair below fixes hosts
 * that were provisioned before that existed and that nobody re-provisions.
 *
 * Narrow on purpose. Signature failures only — a mirror that is merely
 * unreachable is transient and keeps its file. `sources.list.d` only, never
 * `/etc/apt/sources.list`. And never the distro mirrors: if those stop
 * verifying, a loud failure beats a box that silently installs from nowhere.
 */
final class AptSourceRepairScript
{
    /**
     * The bash function both callers embed. Reads a captured apt log as $1.
     * Returns 0 when it removed something (or would have, under dry run), 1
     * otherwise — so a caller can decide whether re-running apt is worthwhile.
     *
     * `DPLY_APT_ROOT` is a test seam, empty in production. `DPLY_APT_PRUNE_DRY_RUN=1`
     * reports without deleting; one function, so the reported set is by
     * construction the set that would be removed.
     */
    public static function pruneFunction(): string
    {
        return <<<'BASH'
dply_prune_unverifiable_apt_sources() {
  local log=$1 dir="${DPLY_APT_ROOT:-}/etc/apt/sources.list.d" pruned=0
  local origin file base dry="${DPLY_APT_PRUNE_DRY_RUN:-0}"

  [ -d "${dir}" ] || return 1

  for origin in $(echo "${log}" \
    | grep -E "EXPKEYSIG|KEYEXPIRED|NO_PUBKEY|is not signed|signatures were invalid" \
    | grep -oE "https?://[^ '\"]+" \
    | sed -E 's#(https?://[^/]+).*#\1#' \
    | sort -u); do
    case "${origin}" in
      *archive.ubuntu.com*|*security.ubuntu.com*|*ports.ubuntu.com*|*mirrors.digitalocean.com*)
        echo "[dply] WARNING: the distro mirror ${origin} failed signature verification — leaving it in place; this needs a human." >&2
        continue
        ;;
    esac

    while IFS= read -r file; do
      [ -n "${file}" ] || continue
      base=$(basename "${file}")
      # `[ … ] && continue` would be a failing AND-list under `set -e`.
      if [ "${base}" = "ubuntu.sources" ]; then
        continue
      fi
      if [ "${dry}" = "1" ]; then
        echo "[dply] WOULD REMOVE ${file} (${origin} cannot be verified)."
      else
        echo "[dply] WARNING: ${origin} cannot be verified (expired or missing signing key) — removing ${file} so it stops breaking apt." >&2
        rm -f "${file}"
      fi
      pruned=1
    done <<EOF
$(grep -rlF "${origin}" "${dir}" 2>/dev/null)
EOF
  done

  [ "${pruned}" = "1" ]
}
BASH;
    }

    /**
     * A self-contained `dply_apt_update` for scripts that run outside a
     * provision run, where the preamble's copy does not exist.
     *
     * Same contract as the preamble's: retries, prunes a source that can never
     * verify, and returns 0 REGARDLESS. Day-two scripts run under `set -e`, so a
     * non-zero return here aborts the whole action for one unrelated broken
     * repo — which is exactly the failure this exists to stop. The install that
     * follows still fails loudly if the package is genuinely unavailable, so
     * nothing is swallowed that matters.
     */
    public static function tolerantUpdateFunction(): string
    {
        $prune = self::pruneFunction();

        return <<<BASH
{$prune}

dply_apt_update() {
  local attempt log
  for attempt in 1 2 3; do
    log=\$(apt-get update -y 2>&1) || true
    echo "\${log}"
    if ! echo "\${log}" | grep -qE "^(E:|Err:)"; then
      return 0
    fi
    if echo "\${log}" | grep -qE "Could not get lock|is held by process"; then
      sleep 5
      continue
    fi
    if dply_prune_unverifiable_apt_sources "\${log}"; then
      continue
    fi
    break
  done
  echo "[dply] WARNING: apt-get update is failing; continuing — the install below will fail explicitly if the package is missing." >&2

  return 0
}
BASH;
    }

    /**
     * Prepend that helper to a script whose `apt-get update` calls have been
     * rewritten to `dply_apt_update`.
     */
    public static function withTolerantApt(string $script): string
    {
        return self::tolerantUpdateFunction()."\n\n".$script;
    }

    /**
     * Standalone day-two script: detect, optionally repair, report. Self-contained
     * — it carries its own copy of the function because nothing outside a
     * provision run has the preamble loaded.
     *
     * Idempotent: on a healthy host it changes nothing and says so, so it is safe
     * to run across a fleet or on a schedule.
     */
    public static function repairScript(bool $dryRun = false): string
    {
        $dry = $dryRun ? '1' : '0';
        $prune = self::pruneFunction();

        return <<<BASH
set -u
export DEBIAN_FRONTEND=noninteractive
DPLY_APT_PRUNE_DRY_RUN={$dry}

{$prune}

echo "[dply-apt-repair] mode: \$([ "\${DPLY_APT_PRUNE_DRY_RUN}" = "1" ] && echo 'dry run (no changes)' || echo 'repair')"
DPLY_LOG=\$(apt-get update -y 2>&1 || true)

if ! echo "\${DPLY_LOG}" | grep -qE "^(E:|Err:)"; then
  echo "[dply-apt-repair] apt-get update is clean — nothing to repair."
  echo "[dply-apt-repair] RESULT: ok removed=0"
  exit 0
fi

echo "[dply-apt-repair] apt-get update reported errors; checking for sources that can never verify."
if dply_prune_unverifiable_apt_sources "\${DPLY_LOG}"; then
  if [ "\${DPLY_APT_PRUNE_DRY_RUN}" = "1" ]; then
    echo "[dply-apt-repair] RESULT: would-repair"
    exit 0
  fi

  DPLY_LOG=\$(apt-get update -y 2>&1 || true)
  if echo "\${DPLY_LOG}" | grep -qE "^(E:|Err:)"; then
    echo "[dply-apt-repair] apt-get update STILL failing after the removal — remaining errors:" >&2
    echo "\${DPLY_LOG}" | grep -E "^(E:|Err:)" >&2
    echo "[dply-apt-repair] RESULT: partial"
    exit 1
  fi

  echo "[dply-apt-repair] apt-get update is clean after the removal."
  echo "[dply-apt-repair] RESULT: repaired"
  exit 0
fi

# Errors that are not signature failures: a mirror that is down, a 404 suite, a
# proxy. Those are transient or need a human, and deleting a source file would
# turn a temporary outage into a permanent missing repo.
echo "[dply-apt-repair] errors are not signature failures — nothing removed. Remaining errors:" >&2
echo "\${DPLY_LOG}" | grep -E "^(E:|Err:)" >&2
echo "[dply-apt-repair] RESULT: no-action"
exit 1
BASH;
    }
}
