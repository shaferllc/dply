<?php

namespace App\Services\Insights\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\Contracts\RevertableInsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Harden sshd by dropping a managed snippet under /etc/ssh/sshd_config.d/.
 * Modern OpenSSH (≥7.3, all current distros) sources that directory via the
 * default Include line in /etc/ssh/sshd_config, so we never edit the main file.
 * Revert is just removing the snippet — no string-diff over operator-edited
 * config, no risk of partial writes corrupting auth.
 *
 * What we set:
 *   PasswordAuthentication no
 *   PermitRootLogin prohibit-password
 *   PermitEmptyPasswords no
 *   X11Forwarding no
 *
 * Order of operations:
 *   1. Sanity-check sudo + Include directive present.
 *   2. Write snippet to a staging path, run `sshd -t -f <main>` (which now
 *      includes our snippet) — if validation fails, remove and bail.
 *   3. systemctl reload ssh (or sshd, depending on distro).
 *
 * Re-application is a no-op (idempotent file write) so re-running after a
 * revert restores cleanly. Existing SSH sessions are unaffected by reload.
 */
class HardenSshConfigFixAction implements InsightFixActionInterface, RevertableInsightFixActionInterface
{
    /** Filename Dply owns under sshd_config.d. The high number wins last-write order. */
    private const SNIPPET_PATH = '/etc/ssh/sshd_config.d/99-dply-hardening.conf';

    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        if (! $server->isReady()) {
            return __('Server is not ready.');
        }
        if (blank($server->ip_address)) {
            return __('Server has no IP address recorded.');
        }
        if (blank($server->ssh_private_key)) {
            return __('SSH access is not configured for this server.');
        }

        return null;
    }

    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $snippet = $this->buildSnippet();
        $snippetEscaped = escapeshellarg($snippet);
        $pathEscaped = escapeshellarg(self::SNIPPET_PATH);

        $script = <<<BASH
set -eu

if ! command -v sshd >/dev/null 2>&1; then
  echo "DPLY_ERR: sshd binary missing"
  exit 1
fi

# Confirm the main sshd_config sources sshd_config.d — if not (very old
# OpenSSH), refuse rather than write a snippet that won't be read.
if ! grep -Eq '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\\.d/\\*\\.conf' /etc/ssh/sshd_config; then
  echo "DPLY_ERR: /etc/ssh/sshd_config does not Include /etc/ssh/sshd_config.d/*.conf"
  exit 1
fi

mkdir -p /etc/ssh/sshd_config.d
# Snapshot the prior content (or empty marker) so revert can detect whether
# we created the file from scratch. Only take the snapshot on the *first*
# apply — re-running with an existing dply-managed snippet must not clobber
# the .dply-prev backup with our own snippet, or revert would later restore
# the dply snippet on top of itself and the operator's original content
# would be lost.
if [ -f {$pathEscaped}.dply-prev ]; then
  echo "DPLY_BACKUP: prior backup already present, keeping it"
elif [ -f {$pathEscaped} ]; then
  cp -p {$pathEscaped} {$pathEscaped}.dply-prev
  echo "DPLY_BACKUP: existing snippet preserved"
else
  echo "DPLY_BACKUP: no prior snippet"
fi

printf '%s\\n' {$snippetEscaped} > {$pathEscaped}
chmod 0644 {$pathEscaped}

# Validate the combined config — sshd -t loads the main file and Includes,
# so any syntax error in our snippet trips it before we reload.
if ! sshd -t 2>&1; then
  echo "DPLY_ERR: sshd -t rejected the new snippet; rolling back"
  if [ -f {$pathEscaped}.dply-prev ]; then
    mv {$pathEscaped}.dply-prev {$pathEscaped}
  else
    rm -f {$pathEscaped}
  fi
  exit 1
fi

# Pick the service name — Debian/Ubuntu use `ssh`, RHEL family uses `sshd`.
# `systemctl cat` actually loads the unit and exits non-zero if it isn't
# resolvable, which is stricter than grepping list-unit-files output.
if systemctl cat ssh.service >/dev/null 2>&1; then
  svc=ssh
else
  svc=sshd
fi

if ! reload_out=\$(systemctl reload "\$svc" 2>&1); then
  if ! restart_out=\$(systemctl restart "\$svc" 2>&1); then
    echo "DPLY_ERR: failed to reload or restart \$svc"
    printf '%s\\n' "\$reload_out"
    printf '%s\\n' "\$restart_out"
    systemctl status "\$svc" --no-pager --lines=20 2>&1 | tail -n 30 || true
    exit 1
  fi
fi
echo "DPLY_OK: reloaded \$svc"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fix-harden-sshd', $script, 30, true);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            return FixResult::failure(__('Failed to apply sshd hardening: :err', ['err' => $e->getMessage()]));
        }

        if (str_contains($buffer, 'DPLY_ERR:')) {
            return FixResult::failure(mb_substr(trim($buffer), 0, 2000));
        }
        if (! str_contains($buffer, 'DPLY_OK:')) {
            return FixResult::failure(__('Apply finished without the expected success marker — refusing to claim success.')."\n".mb_substr(trim($buffer), 0, 1500));
        }

        $this->stampBackup($finding, self::SNIPPET_PATH);

        return FixResult::success(mb_substr(trim($buffer), 0, 2000));
    }

    public function revert(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $pathEscaped = escapeshellarg(self::SNIPPET_PATH);

        $script = <<<BASH
set -eu

# If a prior snippet was preserved, restore it. Otherwise the file gets
# removed entirely. Either way validate before reloading.
if [ -f {$pathEscaped}.dply-prev ]; then
  mv {$pathEscaped}.dply-prev {$pathEscaped}
  echo "DPLY_REVERT: restored prior snippet"
elif [ -f {$pathEscaped} ]; then
  rm -f {$pathEscaped}
  echo "DPLY_REVERT: removed snippet"
else
  echo "DPLY_REVERT: nothing to do (snippet absent)"
fi

if ! sshd -t 2>&1; then
  echo "DPLY_ERR: sshd -t failed after revert — leaving as-is"
  exit 1
fi

if systemctl cat ssh.service >/dev/null 2>&1; then
  svc=ssh
else
  svc=sshd
fi
if ! reload_out=\$(systemctl reload "\$svc" 2>&1); then
  if ! restart_out=\$(systemctl restart "\$svc" 2>&1); then
    echo "DPLY_ERR: failed to reload or restart \$svc"
    printf '%s\\n' "\$reload_out"
    printf '%s\\n' "\$restart_out"
    systemctl status "\$svc" --no-pager --lines=20 2>&1 | tail -n 30 || true
    exit 1
  fi
fi
echo "DPLY_OK: reloaded \$svc"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-revert-harden-sshd', $script, 30, true);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            return FixResult::failure(__('Revert failed: :err', ['err' => $e->getMessage()]));
        }

        if (str_contains($buffer, 'DPLY_ERR:')) {
            return FixResult::failure(mb_substr(trim($buffer), 0, 2000));
        }

        $meta = is_array($finding->meta) ? $finding->meta : [];
        unset($meta['backup_path']);
        $meta['revert_applied_at'] = now()->toIso8601String();
        $finding->forceFill(['meta' => $meta])->save();

        return FixResult::success(mb_substr(trim($buffer), 0, 2000));
    }

    private function buildSnippet(): string
    {
        // Trailing newline + clear ownership banner so an operator who reads
        // the file knows where it came from and that hand-edits will be
        // overwritten by the next apply.
        return <<<'CFG'
# Managed by Dply Insights (ssh_security_posture fix). Edits here will be
# overwritten when the fix is re-applied. Remove this file to revert.
PasswordAuthentication no
PermitRootLogin prohibit-password
PermitEmptyPasswords no
X11Forwarding no

CFG;
    }

    private function stampBackup(InsightFinding $finding, string $path): void
    {
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['backup_path'] = $path;
        $meta['fix_change'] = [
            'snippet_path' => $path,
            'applied' => [
                'PasswordAuthentication' => 'no',
                'PermitRootLogin' => 'prohibit-password',
                'PermitEmptyPasswords' => 'no',
                'X11Forwarding' => 'no',
            ],
        ];
        $finding->forceFill(['meta' => $meta])->save();
    }
}
