<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\ServerProvisionRun;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionScriptPreamble
{


    private function provisionScriptPreamble(string $taskId, ServerProvisionRun $run): string
    {
        $runId = (string) $run->id;

        return <<<BASH
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
DPLY_PROVISION_ROOT=/var/lib/dply/provision/{$runId}
DPLY_PROVISION_BACKUPS="\${DPLY_PROVISION_ROOT}/backups"
mkdir -p "\${DPLY_PROVISION_BACKUPS}"
echo "[dply] provision run {$runId} task {$taskId}"

dply_restore_backups() {
  if [ ! -d "\${DPLY_PROVISION_BACKUPS}" ]; then
    return 0
  fi

  while IFS= read -r statefile; do
    rel="\${statefile#\${DPLY_PROVISION_BACKUPS}/}"
    rel="\${rel%.state}"
    target="/\${rel}"
    state=\$(cat "\${statefile}")
    if [ "\${state}" = "exists" ] && [ -f "\${DPLY_PROVISION_BACKUPS}/\${rel}.bak" ]; then
      mkdir -p "\$(dirname "\${target}")"
      cp -a "\${DPLY_PROVISION_BACKUPS}/\${rel}.bak" "\${target}"
      echo "[dply-rollback] \${rel} :: restored :: Previous config restored"
    elif [ "\${state}" = "missing" ]; then
      rm -f "\${target}"
      echo "[dply-rollback] \${rel} :: removed :: New config removed"
    fi
  done < <(find "\${DPLY_PROVISION_BACKUPS}" -name '*.state' -type f 2>/dev/null)
}

dply_write_file() {
  target=\$(printf '%s' "\$1" | base64 -d)
  payload=\$(printf '%s' "\$2" | base64 -d)
  rel="\${target#/}"
  statefile="\${DPLY_PROVISION_BACKUPS}/\${rel}.state"
  backupfile="\${DPLY_PROVISION_BACKUPS}/\${rel}.bak"
  mkdir -p "\$(dirname "\${statefile}")" "\$(dirname "\${target}")"
  if [ -f "\${target}" ]; then
    cp -a "\${target}" "\${backupfile}"
    printf 'exists' > "\${statefile}"
  else
    printf 'missing' > "\${statefile}"
  fi
  printf '%s' "\${payload}" > "\${target}"
  echo "[dply-rollback] \${rel} :: checkpoint :: Backup recorded"
}

trap 'status=\$?; echo "[dply-rollback] automatic :: started :: Provision failed, attempting safe rollback"; dply_dump_dpkg_diagnostics 2>&1 || true; dply_restore_backups || true; exit \$status' ERR

# Two-phase apt-lock waiter. Cloud-init's first-boot
# unattended-upgrades commonly holds the apt lock for 5-10+ minutes
# on fresh DigitalOcean droplets, which is unacceptable wait latency
# during interactive provisioning. Strategy:
#
#   Phase 1 (0-90s): passive wait. Politely block on cloud-init and
#                    apt locks. Most well-behaved droplets clear in
#                    this window.
#   Phase 2 (>90s):  active eviction. Stop the unattended-upgrades
#                    service/timer, kill any apt-get / unattended-upgr
#                    processes still running, run dpkg --configure -a
#                    to recover any half-installed packages, then
#                    proceed. Faster than waiting 5-10 minutes for
#                    the OS to finish a background task we never
#                    asked for.
#
# Hard fail at 180s — at that point something is genuinely wedged and
# silent waiting just hides the problem.
dply_apt_locks_held() {
  fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
    || fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
    || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
    || pgrep -x apt-get >/dev/null 2>&1 \
    || pgrep -x unattended-upgr >/dev/null 2>&1
}

dply_wait_for_apt_locks() {
  # Fast-path: if our pre-empt block at script start already disabled
  # cloud-init + the auto-upgrade timers AND the locks aren't held by
  # anything else, we're good — skip the cloud-init status --wait
  # (which can sit there for 60s on droplets that booted recently).
  if ! dply_apt_locks_held; then
    return 0
  fi

  # cloud-init may still be honouring an in-flight `apt-get` from before
  # our pre-empt killed it. Try a short status wait so we don't race
  # cloud-init's last-gasp cleanup, but cap it tight (5s, not 60s).
  if command -v cloud-init >/dev/null 2>&1; then
    timeout 5 cloud-init status --wait >/dev/null 2>&1 || true
  fi

  local waited=0
  # Polite wait window before forcible eviction. Tighter than the old
  # 90s because the pre-empt block at script start should have already
  # cleared everything — if locks are still held this far in, the
  # blocking process is stuck rather than legitimately upgrading.
  local polite=15

  while dply_apt_locks_held; do
    if [ "\${waited}" -lt "\${polite}" ]; then
      echo "[dply] apt is busy (waited \${waited}s — likely cloud-init unattended-upgrades); polite wait, retry in 5s..."
      sleep 5
      waited=\$((waited + 5))
      continue
    fi

    if [ "\${waited}" -eq "\${polite}" ]; then
      echo "[dply] apt still busy after \${polite}s — evicting unattended-upgrades to unblock provisioning."
      # Cloud-init too — its modules can re-spawn apt children that
      # the pre-empt block at script start may have missed.
      systemctl stop cloud-init.target cloud-config.service cloud-final.service cloud-init.service cloud-init-local.service >/dev/null 2>&1 || true
      systemctl stop unattended-upgrades.service >/dev/null 2>&1 || true
      systemctl disable unattended-upgrades.service >/dev/null 2>&1 || true
      systemctl stop apt-daily.timer apt-daily.service >/dev/null 2>&1 || true
      systemctl stop apt-daily-upgrade.timer apt-daily-upgrade.service >/dev/null 2>&1 || true
      pkill -TERM -x unattended-upgr >/dev/null 2>&1 || true
      pkill -TERM -x apt-get >/dev/null 2>&1 || true
      pkill -TERM -x apt >/dev/null 2>&1 || true
      sleep 2
      pkill -KILL -x unattended-upgr >/dev/null 2>&1 || true
      pkill -KILL -x apt-get >/dev/null 2>&1 || true
      pkill -KILL -x apt >/dev/null 2>&1 || true
      # Drop dpkg lock files left behind by SIGKILL.
      rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1 || true
      # Half-installed package recovery after a kill -9.
      dpkg --configure -a >/dev/null 2>&1 || true
      waited=\$((waited + 5))
      sleep 2
      continue
    fi

    if [ "\${waited}" -ge 180 ]; then
      echo "[dply] ERROR: apt lock still held 90s after eviction — something is wedged." >&2
      echo "[dply] Diagnose on the host:" >&2
      echo "[dply]   ps auxf | grep -E 'apt|unattended|dpkg'" >&2
      echo "[dply]   lsof /var/lib/dpkg/lock-frontend" >&2
      return 1
    fi

    echo "[dply] post-eviction wait (\${waited}s); retry in 5s..."
    sleep 5
    waited=\$((waited + 5))
  done
}

# Heal any half-configured dpkg state left behind by a prior failed
# install, an OOM kill, an apt eviction, or cloud-init's unattended-
# upgrades being interrupted mid-flight. Symptom that drives this:
#
#   E: Sub-process /usr/bin/dpkg returned an error code (1)
#   N not fully installed or removed
#
# Three-level recovery, escalating only when the gentler step fails:
#   1. dpkg --configure -a
#      Re-runs postinst for every half-configured package. Fixes the
#      common case where postinst was killed (OOM, our eviction).
#   2. apt-get install -f
#      Repairs unmet dependencies that were left behind. Catches the
#      case where the package is fine but a missing dep blocks
#      reconfiguration.
#   3. dpkg --purge --force-all <broken pkg>
#      The escape hatch. If postinst itself is the bug (mysql-server
#      8.4 + missing locale + missing /var/run/mysqld), levels 1-2
#      will keep failing forever in a loop. Purge the broken package
#      so the normal install flow downstream gets a clean slate.
#      The package's own install step will reinstall it from a known-
#      working state.
dply_dump_dpkg_diagnostics() {
  echo "[dply-diag] ===== dpkg failure diagnostics =====" >&2
  echo "[dply-diag] non-ok packages (status != ii/rc/un):" >&2
  local broken_list
  broken_list=\$(dpkg -l 2>/dev/null \\
    | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print "  "\$1, \$2, \$3 }')
  if [ -n "\${broken_list}" ]; then
    echo "\${broken_list}" >&2
  else
    echo "  (none flagged — failure may be a postinst that exited 1 without leaving status state)" >&2
  fi

  echo "[dply-diag] last 50 lines of /var/log/apt/term.log:" >&2
  tail -n 50 /var/log/apt/term.log 2>/dev/null | sed 's/^/  /' >&2 \\
    || echo "  (term.log unavailable)" >&2

  echo "[dply-diag] last 30 lines of /var/log/dpkg.log:" >&2
  tail -n 30 /var/log/dpkg.log 2>/dev/null | sed 's/^/  /' >&2 \\
    || echo "  (dpkg.log unavailable)" >&2

  # MySQL-specific deep diagnostics. The postinst calls
  # systemctl start mysql, and the daemon's actual startup error
  # only lands in the systemd journal — neither apt's term.log
  # nor dpkg.log capture mysqld's stderr. Without these blocks
  # we keep seeing "configure → half-configured in <1s" without
  # any root-cause signal. Only emit when mysql-server is in the
  # broken list (or installed at all) so non-mysql failures aren't
  # noisy.
  if echo "\${broken_list}" | grep -qE 'mysql-server|mariadb-server' \\
     || dpkg -l mysql-server-* mariadb-server-* 2>/dev/null | grep -qE '^[a-zA-Z]{2}[ \\t]'; then
    echo "[dply-diag] mysql/mariadb appears in package state — pulling daemon diagnostics:" >&2

    echo "[dply-diag]   journalctl -u mysql (last 80 lines):" >&2
    journalctl -u mysql --no-pager -n 80 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (journalctl -u mysql unavailable)" >&2

    echo "[dply-diag]   journalctl -u mariadb (last 40 lines):" >&2
    journalctl -u mariadb --no-pager -n 40 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (no mariadb journal)" >&2

    echo "[dply-diag]   /var/log/mysql/error.log tail (last 50 lines):" >&2
    tail -n 50 /var/log/mysql/error.log 2>/dev/null | sed 's/^/    /' >&2 \\
      || echo "    (no /var/log/mysql/error.log yet)" >&2

    echo "[dply-diag]   filesystem state:" >&2
    ls -lad /var/lib/mysql /var/log/mysql /var/run/mysqld /etc/mysql 2>&1 | sed 's/^/    /' >&2

    echo "[dply-diag]   mysqld --validate-config:" >&2
    if command -v mysqld >/dev/null 2>&1; then
      sudo -u mysql mysqld --validate-config 2>&1 | head -n 30 | sed 's/^/    /' >&2 \\
        || mysqld --validate-config 2>&1 | head -n 30 | sed 's/^/    /' >&2 \\
        || true
    else
      echo "    (mysqld binary not present — package failed before unpack completed)" >&2
    fi

    echo "[dply-diag]   AppArmor status for mysqld:" >&2
    aa-status 2>/dev/null | grep -i mysql | sed 's/^/    /' >&2 \\
      || echo "    (apparmor not active or no mysql profile)" >&2

    echo "[dply-diag]   memory snapshot (mysql 8.0 needs ~512MB to initialise):" >&2
    free -h 2>/dev/null | sed 's/^/    /' >&2

    echo "[dply-diag]   processes still holding mysql files:" >&2
    fuser -v /var/lib/mysql 2>&1 | sed 's/^/    /' >&2 || true
  fi

  echo "[dply-diag] ===== end diagnostics =====" >&2
}

dply_repair_dpkg_state() {
  dply_wait_for_apt_locks || return 1

  # /^[a-zA-Z]{2}[ \\t]/  — exactly two status chars + whitespace.
  # Without the {2} bound, the dpkg -l header line "Desired=Unknown..."
  # also matched and fed garbage into the broken-list. Tightening to
  # exactly two characters skips the header cleanly.
  local broken
  broken=\$(dpkg -l 2>/dev/null | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print \$2 }')

  if [ -z "\${broken}" ]; then
    return 0
  fi

  echo "[dply] detected half-configured packages, running dpkg --configure -a to heal:"
  echo "\${broken}" | sed 's/^/[dply]   /'

  if dpkg --configure -a; then
    DEBIAN_FRONTEND=noninteractive apt-get install -f -y \\
      || echo "[dply] WARNING: apt-get install -f could not auto-fix dependencies."
  else
    echo "[dply] dpkg --configure -a failed; trying apt-get install -f..."
    DEBIAN_FRONTEND=noninteractive apt-get install -f -y || true
  fi

  # Re-check; if any package is STILL half-configured after both
  # repair attempts, the postinst itself is broken. Purge with
  # --force-all so the normal install flow reinstalls it cleanly.
  local still_broken
  still_broken=\$(dpkg -l 2>/dev/null | awk '/^[a-zA-Z]{2}[ \\t]/ && \$1 !~ /^(ii|rc|un)\$/ { print \$2 }')

  if [ -n "\${still_broken}" ]; then
    echo "[dply] gentle repair failed; purging stuck packages (will be reinstalled by their own install step):"
    echo "\${still_broken}" | sed 's/^/[dply]   /'

    # mysql-server's postinst calls mysql_install_db, which refuses to
    # initialise into a non-empty /var/lib/mysql. A previous failed
    # install left files there, so even after dpkg --purge clears the
    # package the data directory survives — and the very next reinstall
    # bombs identically: "data directory not empty". Symptom in
    # /var/log/dpkg.log: configure → half-configured in <1 second,
    # repeating every retry. Same logic applies to mariadb. Stop the
    # service and nuke the data + log directories so the next install
    # gets a clean slate.
    if echo "\${still_broken}" | grep -qE '^(mysql-server|mariadb-server)'; then
      echo "[dply] mysql/mariadb among broken packages — wiping stale data dirs so reinstall can initialise cleanly."
      systemctl stop mysql mariadb >/dev/null 2>&1 || true
      rm -rf /var/lib/mysql /var/log/mysql /etc/mysql
    fi

    # shellcheck disable=SC2086
    DEBIAN_FRONTEND=noninteractive dpkg --purge --force-all \${still_broken} \\
      || { echo "[dply] ERROR: even --force-all purge failed; manual intervention required." >&2; return 1; }
    echo "[dply] purge complete; downstream install steps will reinstall."
  fi
}

# Lock-aware `apt-get update` with retry. A bare `apt-get update -y` under
# `set -e` aborts the whole provision on a transient mirror/lock blip — and a
# third-party repo (caddy/keydb/dragonfly/pgdg) that's briefly slow is exactly
# that. Retries on lock contention and on E:/Err: output, then returns success
# regardless so the following install step (which has its own retry and fails
# hard on genuinely-missing packages) decides the real outcome.
dply_apt_update() {
  local attempt log marker=/var/lib/dply/apt-updated.stamp
  # Skip the network round-trip when no apt source has changed since the last
  # successful update. apt-get update re-fetches EVERY configured source each
  # call, so 5-7 sequential updates (one per third-party repo) re-download the
  # same indexes. With this guard, the common stack (all packages from the
  # distro repo) does exactly one update, and a stack that adds N third-party
  # repos updates only when a new .list/keyring actually appears.
  if [ -f "\${marker}" ] \\
     && [ -z "\$(find /etc/apt/sources.list /etc/apt/sources.list.d /etc/apt/keyrings -newer "\${marker}" 2>/dev/null | head -n1)" ]; then
    echo "[dply] apt sources unchanged since last update — skipping apt-get update."
    return 0
  fi
  for attempt in 1 2 3 4; do
    dply_wait_for_apt_locks || return 1
    log=\$(apt-get update -y 2>&1) || true
    echo "\${log}"
    if echo "\${log}" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"; then
      echo "[dply] apt-get update hit a lock (attempt \${attempt}/4) — retrying in 10s."
      sleep 10
      continue
    fi
    if ! echo "\${log}" | grep -qE "^(E:|Err:)"; then
      mkdir -p /var/lib/dply && touch "\${marker}"
      return 0
    fi
    echo "[dply] apt-get update reported errors (attempt \${attempt}/4) — retrying in 10s."
    sleep 10
  done
  echo "[dply] WARNING: apt-get update still failing after retries; continuing — package installs will retry or fail explicitly." >&2
  return 0
}

BASH;
    }
}
