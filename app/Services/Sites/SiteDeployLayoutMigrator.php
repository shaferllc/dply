<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * Executes a one-time on-disk layout migration that was ARMED when a site's
 * deployment method changed (see docs/DEPLOYMENT_METHODS.md). It runs at the
 * END of a successful deploy — after the new layout is built, activated and
 * health-checked — so a failed deploy can never destroy the old layout.
 *
 * The migration is recorded in `meta['deploy_layout_migration'] = {from, to,
 * armed_at}`. Cleanup is archive-then-prune: old-layout files are MOVED into
 * `<root>/.dply-layout-archive-<ts>/` (kept: last N) rather than deleted, so a
 * bad migration is reversible. The flag is cleared once the move succeeds.
 *
 * This is also how prod's flat-checkout hybrid is retired: arm a flat→atomic
 * migration on the control-plane site and its next deploy self-heals the root.
 */
class SiteDeployLayoutMigrator
{
    /** Directories the atomic layout owns — never archived as flat cruft. */
    private const ATOMIC_KEEP = ['current', 'releases', 'shared', 'repo', '.dply'];

    private const ARCHIVES_TO_KEEP = 3;

    public function migrateIfArmed(Site $site, SshConnection $ssh, string $timestamp): string
    {
        $meta = ($site->meta );
        $armed = $meta['deploy_layout_migration'] ?? null;
        if (! is_array($armed) || empty($armed['to'])) {
            return '';
        }

        $from = (string) ($armed['from'] ?? '');
        $to = (string) ($armed['to'] ?? '');
        $base = rtrim($site->effectiveRepositoryPath(), '/');
        $archive = $base.'/.dply-layout-archive-'.$timestamp;

        $log = sprintf("\n[dply] LAYOUT MIGRATE → %s → %s (archive: %s)\n", $from ?: '?', $to, $archive);

        // Placement is what actually changes the on-disk shape. flat↔atomic is
        // the only transition with a disk layout difference today; blue-green /
        // image refine the atomic tree and are handled by their own engines.
        if ($to === 'atomic' && $from === 'flat') {
            $log .= $this->flatToAtomic($ssh, $base, $archive);
        } elseif ($to === 'flat' && $from === 'atomic') {
            $log .= $this->atomicToFlat($ssh, $base, $archive);
        } else {
            $log .= "[dply] no disk-layout change required for this transition\n";
        }

        $log .= $this->pruneArchives($ssh, $base);

        // Clear the armed flag — migration done (or no-op).
        unset($meta['deploy_layout_migration']);
        $site->forceFill(['meta' => $meta])->save();

        return $log;
    }

    /**
     * Archive the leftover flat checkout at the root, leaving only the atomic
     * tree (current / releases / shared / repo / .dply). The atomic deploy has
     * already built+activated a release, so nothing served reads the root files.
     */
    private function flatToAtomic(SshConnection $ssh, string $base, string $archive): string
    {
        // Names are fixed, shell-safe literals (no metacharacters) — inline them
        // directly as a case-pattern alternation.
        $keepCase = implode('|', self::ATOMIC_KEEP);

        $script = <<<BASH
set -e
BASE={$this->sh($base)}
ARCHIVE={$this->sh($archive)}
mkdir -p "\$ARCHIVE"
shopt -s dotglob nullglob
moved=0
for entry in "\$BASE"/*; do
  name=\$(basename "\$entry")
  case "\$name" in
    {$keepCase}|.dply-layout-archive-*) continue ;;
  esac
  mv "\$entry" "\$ARCHIVE"/ 2>/dev/null && moved=\$((moved+1)) || echo "[dply]   could not archive \$name"
done
echo "[dply]   archived \$moved root entr(y/ies) of the old flat checkout"
echo "[dply]   root now: \$(ls -A "\$BASE" | tr '\n' ' ')"
BASH;

        return $ssh->exec($script, 120);
    }

    /**
     * Collapse the atomic tree back to a flat checkout: preserve the live env by
     * materialising shared/.env → root .env, then archive the atomic scaffolding.
     * The flat (simple) deploy has already checked out the app at the root.
     */
    private function atomicToFlat(SshConnection $ssh, string $base, string $archive): string
    {
        $atomicDirs = implode(' ', array_map(
            fn (string $d): string => $this->sh($d),
            ['current', 'releases', 'shared', 'repo'],
        ));

        $script = <<<BASH
set -e
BASE={$this->sh($base)}
ARCHIVE={$this->sh($archive)}
mkdir -p "\$ARCHIVE"
# Preserve env: the flat layout reads .env from the root, the atomic layout kept
# it in shared/.env. Materialise it if the root has no real .env yet.
if [ -f "\$BASE/shared/.env" ] && [ ! -f "\$BASE/.env" ]; then
  cp -a "\$BASE/shared/.env" "\$BASE/.env"
  echo "[dply]   materialised shared/.env → root .env"
fi
for d in {$atomicDirs}; do
  if [ -e "\$BASE/\$d" ]; then mv "\$BASE/\$d" "\$ARCHIVE"/ 2>/dev/null && echo "[dply]   archived \$d" || echo "[dply]   could not archive \$d"; fi
done
echo "[dply]   root now: \$(ls -A "\$BASE" | tr '\n' ' ')"
BASH;

        return $ssh->exec($script, 120);
    }

    private function pruneArchives(SshConnection $ssh, string $base): string
    {
        // Keep the newest N archives; `tail -n +(N+1)` lists everything older.
        $offset = self::ARCHIVES_TO_KEEP + 1;

        $script = <<<BASH
BASE={$this->sh($base)}
ls -dt "\$BASE"/.dply-layout-archive-* 2>/dev/null | tail -n +{$offset} | while read -r old; do
  rm -rf "\$old" && echo "[dply]   pruned old archive \$(basename "\$old")"
done
true
BASH;

        return $ssh->exec($script, 60);
    }

    private function sh(string $value): string
    {
        return escapeshellarg($value);
    }
}
