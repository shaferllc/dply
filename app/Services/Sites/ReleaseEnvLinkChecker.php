<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnectionFactory;

/**
 * Verifies that every atomic-release `.env` is a symlink to the site's canonical
 * external env file, and reports any release that has drifted to a real file (or
 * a symlink pointing elsewhere).
 *
 * Why this matters: when a site relocates its `.env` outside the docroot
 * (`env_file_path` set), {@see AtomicSiteDeployer} symlinks each release's
 * project-root `.env` at that one canonical file, so a single push to it updates
 * every release at once. If a release instead carries a *real* `.env` (e.g. it
 * predates the shared layout, or was hand-edited), an env push silently skips it
 * — and a rollback to that release would serve stale/wrong secrets. This check
 * surfaces that drift right in the push banner.
 *
 * Not applicable (returns applicable=false, no findings) for:
 *   - non-atomic (flat) sites — there are no release folders;
 *   - default-layout sites (no `env_file_path`) — each release legitimately
 *     owns its own `.env`, so a real file is correct, not drift;
 *   - hosts that don't expose a server `.env`.
 */
final class ReleaseEnvLinkChecker
{
    public function __construct(
        private SshConnectionFactory $sshFactory,
    ) {}

    /**
     * @return array{applicable: bool, canonical: string, checked: int, drifted: list<array{release: string, kind: string, target: ?string}>}
     */
    /** @return array<string, mixed> */
    public function check(Site $site): array
    {
        $notApplicable = ['applicable' => false, 'canonical' => '', 'checked' => 0, 'drifted' => []];

        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            return $notApplicable;
        }
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            return $notApplicable;
        }
        // Only the atomic + external-env layout symlinks release .env → shared file.
        if (! $site->isAtomicDeploys()) {
            return $notApplicable;
        }
        $envPath = trim((string) ($site->env_file_path ?? ''));
        if ($envPath === '') {
            return $notApplicable;
        }

        $base = rtrim($site->effectiveRepositoryPath(), '/');
        $ssh = $this->sshFactory->forServer($server);
        $raw = $ssh->exec($this->buildScript($base, $envPath), 60);

        return $this->parse($raw);
    }

    /**
     * Build the remote scan. Prints one tab-free, space-delimited line per
     * release: "<name> OK|REALFILE|MISSING|OTHER:<resolved-target>", preceded by
     * a "CANON <resolved-canonical>" line. readlink -f canonicalises both sides
     * so a relative vs absolute symlink target still compares equal.
     */
    private function buildScript(string $base, string $envPath): string
    {
        $baseEsc = escapeshellarg($base);
        $envEsc = escapeshellarg($envPath);

        // No `set -e`: a single unreadable release must not abort the whole scan.
        return <<<SH
            CANON=\$(readlink -f $envEsc 2>/dev/null || echo '')
            echo "CANON \$CANON"
            for d in $baseEsc/releases/*/; do
              [ -d "\$d" ] || continue
              name=\$(basename "\$d")
              f="\${d}.env"
              if [ -L "\$f" ]; then
                tgt=\$(readlink -f "\$f" 2>/dev/null || echo '')
                if [ -n "\$CANON" ] && [ "\$tgt" = "\$CANON" ]; then
                  echo "\$name OK"
                else
                  echo "\$name OTHER:\$tgt"
                fi
              elif [ -f "\$f" ]; then
                echo "\$name REALFILE"
              else
                echo "\$name MISSING"
              fi
            done
            SH;
    }

    /**
     * @return array{applicable: bool, canonical: string, checked: int, drifted: list<array{release: string, kind: string, target: ?string}>}
     */
    /** @return array<string, mixed> */
    public function parse(string $raw): array
    {
        $canonical = '';
        $checked = 0;
        $drifted = [];

        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$key, $rest] = array_pad(explode(' ', $line, 2), 2, '');
            $rest = trim($rest);

            if ($key === 'CANON') {
                $canonical = $rest;

                continue;
            }

            $checked++;

            if ($rest === 'OK') {
                continue;
            }

            if ($rest === 'REALFILE') {
                $drifted[] = ['release' => $key, 'kind' => 'real_file', 'target' => null];
            } elseif ($rest === 'MISSING') {
                $drifted[] = ['release' => $key, 'kind' => 'missing', 'target' => null];
            } elseif (str_starts_with($rest, 'OTHER:')) {
                $target = substr($rest, strlen('OTHER:'));
                $drifted[] = ['release' => $key, 'kind' => 'wrong_target', 'target' => $target !== '' ? $target : null];
            }
        }

        return [
            'applicable' => true,
            'canonical' => $canonical,
            'checked' => $checked,
            'drifted' => $drifted,
        ];
    }
}
