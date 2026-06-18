<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Shared bash helpers for apt/dpkg lock contention — used by manage SSH scripts
 * (which do not inherit the provision preamble from {@see RunSetupScriptJob}).
 */
final class ServerAptLockBash
{
    /**
     * Minimal lock-wait helpers for one-off manage scripts.
     */
    public static function managePreamble(): string
    {
        return <<<'BASH'
set -e
dply_apt_locks_held() {
  fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
    || fuser /var/lib/dpkg/lock >/dev/null 2>&1 \
    || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
    || pgrep -x apt-get >/dev/null 2>&1 \
    || pgrep -x unattended-upgr >/dev/null 2>&1
}

dply_wait_for_apt_locks() {
  if ! dply_apt_locks_held; then
    return 0
  fi
  if command -v cloud-init >/dev/null 2>&1; then
    timeout 5 cloud-init status --wait >/dev/null 2>&1 || true
  fi
  local waited=0 polite=30
  while dply_apt_locks_held; do
    if [ "${waited}" -lt "${polite}" ]; then
      echo "[dply] apt is busy (waited ${waited}s); polite wait, retry in 5s..."
      sleep 5
      waited=$((waited + 5))
      continue
    fi
    if [ "${waited}" -eq "${polite}" ]; then
      echo "[dply] apt still busy after ${polite}s — stopping background apt timers."
      systemctl stop unattended-upgrades.service apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true
      pkill -TERM -x apt-get >/dev/null 2>&1 || true
      pkill -TERM -x unattended-upgr >/dev/null 2>&1 || true
      sleep 2
      pkill -KILL -x apt-get >/dev/null 2>&1 || true
      rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1 || true
      dpkg --configure -a >/dev/null 2>&1 || true
      waited=$((waited + 5))
      continue
    fi
    if [ "${waited}" -ge 180 ]; then
      echo "[dply] ERROR: apt lock still held after 180s." >&2
      return 1
    fi
    echo "[dply] post-eviction wait (${waited}s); retry in 5s..."
    sleep 5
    waited=$((waited + 5))
  done
}

dply_apt_log_has_lock_error() {
  echo "$1" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"
}

BASH;
    }

    public static function scriptUsesApt(string $script): bool
    {
        return str_contains($script, 'apt-get') || str_contains($script, 'apt ');
    }

    public static function wrapManageScript(string $script): string
    {
        if (! self::scriptUsesApt($script)) {
            return $script;
        }

        return self::managePreamble()."\n".$script;
    }

    /**
     * @param  array<string, mixed> $patterns
     */
    public static function outputLooksLikeAptLockFailure(string $output, ?int $exitCode = null): bool
    {
        if ($exitCode === 100) {
            return true;
        }

        $patterns = [
            '/Could not get lock/i',
            '/Unable to acquire the dpkg frontend lock/i',
            '/is held by process/i',
            '/\/var\/lib\/dpkg\/lock-frontend/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return true;
            }
        }

        return false;
    }
}
