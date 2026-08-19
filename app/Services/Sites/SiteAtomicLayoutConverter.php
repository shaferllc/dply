<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteRelease;
use App\Services\SshConnection;

/**
 * Inspects and converts one host's checkout into the atomic layout
 * (releases/ + current + shared/). Does not flip deploy_strategy.
 */
class SiteAtomicLayoutConverter
{
    public const LAYOUT_EMPTY = 'empty';

    public const LAYOUT_FLAT = 'flat';

    public const LAYOUT_HYBRID = 'hybrid';

    public const LAYOUT_ATOMIC = 'atomic';

    /** @var list<string> */
    private const ATOMIC_KEEP = ['current', 'releases', 'shared', 'repo', '.dply'];

    /**
     * @return array{layout: string, current: string, git_sha: string, has_env: bool, has_storage: bool}
     */
    public function inspect(SshConnection $ssh, string $base): array
    {
        $baseEsc = $this->sh($base);
        $keepCase = implode('|', self::ATOMIC_KEEP);

        $script = <<<BASH
set +e
BASE={$baseEsc}
if [ ! -d "\$BASE" ] || [ -z "\$(ls -A "\$BASE" 2>/dev/null)" ]; then
  echo DPLY_LAYOUT=empty
  echo DPLY_CURRENT=
  echo DPLY_GIT_SHA=
  echo DPLY_HAS_ENV=0
  echo DPLY_HAS_STORAGE=0
  exit 0
fi
if [ -L "\$BASE/current" ] && [ -d "\$BASE/releases" ]; then
  leftover=0
  shopt -s dotglob nullglob
  for entry in "\$BASE"/*; do
    name=\$(basename "\$entry")
    case "\$name" in
      {$keepCase}|.dply-layout-archive-*) ;;
      *) leftover=1 ;;
    esac
  done
  if [ "\$leftover" = 1 ]; then echo DPLY_LAYOUT=hybrid; else echo DPLY_LAYOUT=atomic; fi
  echo DPLY_CURRENT=\$(readlink -f "\$BASE/current" 2>/dev/null)
else
  echo DPLY_LAYOUT=flat
  echo DPLY_CURRENT=
fi
TARGET=\$(readlink -f "\$BASE/current" 2>/dev/null)
if [ -z "\$TARGET" ]; then TARGET="\$BASE"; fi
echo DPLY_GIT_SHA=\$(git -C "\$TARGET" rev-parse --verify HEAD 2>/dev/null)
if [ -f "\$TARGET/.env" ] || [ -f "\$BASE/.env" ]; then echo DPLY_HAS_ENV=1; else echo DPLY_HAS_ENV=0; fi
if [ -d "\$TARGET/storage" ] || [ -d "\$BASE/storage" ]; then echo DPLY_HAS_STORAGE=1; else echo DPLY_HAS_STORAGE=0; fi
BASH;

        return $this->parseInspect($ssh->exec($script, 60));
    }

    /**
     * @return array{log: string, layout: string, folder: ?string, git_sha: ?string, skipped: bool}
     */
    public function convert(Site $site, SshConnection $ssh): array
    {
        $base = rtrim($site->effectiveRepositoryPath(), '/');
        if ($base === '') {
            return ['log' => "[dply] no repository path — skip\n", 'layout' => self::LAYOUT_EMPTY, 'folder' => null, 'git_sha' => null, 'skipped' => true];
        }

        $info = $this->inspect($ssh, $base);
        $layout = $info['layout'];
        $log = sprintf("[dply] inspect %s → %s\n", $base, $layout);

        if ($layout === self::LAYOUT_EMPTY) {
            $log .= "[dply] no checkout — skip disk convert\n";

            return ['log' => $log, 'layout' => $layout, 'folder' => null, 'git_sha' => null, 'skipped' => true];
        }

        $folder = $layout === self::LAYOUT_FLAT
            ? gmdate('YmdHis')
            : basename($info['current']);
        if ($folder === '' || $folder === '.') {
            $folder = gmdate('YmdHis');
        }

        if ($layout === self::LAYOUT_FLAT) {
            $log .= $this->copyFlatToRelease($ssh, $base, $folder);
        }

        $releaseDir = $layout === self::LAYOUT_FLAT
            ? $base.'/releases/'.$folder
            : ($info['current'] !== '' ? $info['current'] : $base.'/releases/'.$folder);

        $log .= $this->attachShared($ssh, $base, $releaseDir);

        if ($layout === self::LAYOUT_FLAT) {
            $log .= $this->pointCurrent($ssh, $base, $releaseDir);
        }

        $sha = $info['git_sha'] !== '' ? $info['git_sha'] : $this->readGitSha($ssh, $releaseDir);
        $this->ensureSiteRelease($site, $folder, $sha !== '' ? $sha : null);

        return ['log' => $log, 'layout' => $layout, 'folder' => $folder, 'git_sha' => $sha !== '' ? $sha : null, 'skipped' => false];
    }

    public function archiveLeftoverRoot(SshConnection $ssh, string $base, string $timestamp): string
    {
        return app(SiteDeployLayoutMigrator::class)->archiveLeftoverFlatRoot($ssh, $base, $timestamp);
    }

    private function copyFlatToRelease(SshConnection $ssh, string $base, string $folder): string
    {
        $baseEsc = $this->sh($base);
        $folderEsc = $this->sh($folder);
        $keepCase = implode('|', self::ATOMIC_KEEP);

        $script = <<<BASH
set -e
BASE={$baseEsc}
FOLDER={$folderEsc}
RELEASE="\$BASE/releases/\$FOLDER"
need=\$(du -sm "\$BASE" 2>/dev/null | awk '{print \$1}')
avail=\$(df -Pm "\$BASE" 2>/dev/null | awk 'NR==2 {print \$4}')
if [ -n "\$need" ] && [ -n "\$avail" ] && [ "\$avail" -lt "\$need" ]; then
  echo "[dply] DISK: need \${need}M, have \${avail}M — refusing copy"
  exit 1
fi
mkdir -p "\$BASE/releases" "\$BASE/shared" "\$RELEASE"
shopt -s dotglob nullglob
copied=0
for entry in "\$BASE"/*; do
  name=\$(basename "\$entry")
  case "\$name" in
    {$keepCase}|.dply-layout-archive-*) continue ;;
  esac
  cp -a "\$entry" "\$RELEASE/" && copied=\$((copied+1))
done
echo "[dply] copied \$copied root entr(y/ies) → \$RELEASE"
BASH;

        return $ssh->exec($script, 600);
    }

    private function attachShared(SshConnection $ssh, string $base, string $releaseDir): string
    {
        $script = <<<BASH
set -e
BASE={$this->sh($base)}
RELEASE={$this->sh($releaseDir)}
mkdir -p "\$BASE/shared"
if [ -f "\$RELEASE/.env" ] && [ ! -L "\$RELEASE/.env" ]; then
  if [ ! -f "\$BASE/shared/.env" ]; then
    cp -a "\$RELEASE/.env" "\$BASE/shared/.env"
    echo "[dply] seeded shared/.env from release"
  fi
  rm -f "\$RELEASE/.env"
  ln -sfn "\$BASE/shared/.env" "\$RELEASE/.env"
  echo "[dply] linked release .env → shared/.env"
elif [ -f "\$BASE/.env" ] && [ ! -f "\$BASE/shared/.env" ]; then
  cp -a "\$BASE/.env" "\$BASE/shared/.env"
  ln -sfn "\$BASE/shared/.env" "\$RELEASE/.env"
  echo "[dply] seeded shared/.env from flat root"
fi
if [ -d "\$RELEASE/storage" ] && [ ! -L "\$RELEASE/storage" ]; then
  if [ ! -e "\$BASE/shared/storage" ]; then
    cp -a "\$RELEASE/storage" "\$BASE/shared/storage"
    echo "[dply] seeded shared/storage from release"
  fi
  rm -rf "\$RELEASE/storage"
  ln -sfn "\$BASE/shared/storage" "\$RELEASE/storage"
  echo "[dply] linked release storage → shared/storage"
elif [ -d "\$BASE/storage" ] && [ ! -e "\$BASE/shared/storage" ]; then
  cp -a "\$BASE/storage" "\$BASE/shared/storage"
  ln -sfn "\$BASE/shared/storage" "\$RELEASE/storage"
  echo "[dply] seeded shared/storage from flat root"
fi
BASH;

        return $ssh->exec($script, 180);
    }

    private function pointCurrent(SshConnection $ssh, string $base, string $releaseDir): string
    {
        return $ssh->exec(sprintf(
            'ln -sfn %s %s/current && echo "[dply] current → %s"',
            $this->sh($releaseDir),
            $this->sh($base),
            $releaseDir
        ), 30);
    }

    private function readGitSha(SshConnection $ssh, string $releaseDir): string
    {
        return trim($ssh->exec(sprintf('git -C %s rev-parse --verify HEAD 2>/dev/null', $this->sh($releaseDir)), 15));
    }

    private function ensureSiteRelease(Site $site, string $folder, ?string $gitSha): void
    {
        $existing = SiteRelease::query()
            ->where('site_id', $site->id)
            ->where('folder', $folder)
            ->first();

        if ($existing !== null) {
            SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
            $existing->forceFill(['is_active' => true, 'git_sha' => $gitSha ?? $existing->git_sha])->save();

            return;
        }

        SiteRelease::query()->where('site_id', $site->id)->update(['is_active' => false]);
        SiteRelease::query()->create([
            'site_id' => $site->id,
            'folder' => $folder,
            'git_sha' => $gitSha,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{layout: string, current: string, git_sha: string, has_env: bool, has_storage: bool}
     */
    private function parseInspect(string $output): array
    {
        $layout = self::LAYOUT_FLAT;
        $current = '';
        $gitSha = '';
        $hasEnv = false;
        $hasStorage = false;

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'DPLY_LAYOUT=')) {
                $value = substr($line, 12);
                if (in_array($value, [self::LAYOUT_EMPTY, self::LAYOUT_FLAT, self::LAYOUT_HYBRID, self::LAYOUT_ATOMIC], true)) {
                    $layout = $value;
                }
            } elseif (str_starts_with($line, 'DPLY_CURRENT=')) {
                $current = trim(substr($line, 13));
            } elseif (str_starts_with($line, 'DPLY_GIT_SHA=')) {
                $gitSha = trim(substr($line, 13));
            } elseif (str_starts_with($line, 'DPLY_HAS_ENV=')) {
                $hasEnv = substr($line, 13) === '1';
            } elseif (str_starts_with($line, 'DPLY_HAS_STORAGE=')) {
                $hasStorage = substr($line, strlen('DPLY_HAS_STORAGE=')) === '1';
            }
        }

        return [
            'layout' => $layout,
            'current' => $current,
            'git_sha' => $gitSha,
            'has_env' => $hasEnv,
            'has_storage' => $hasStorage,
        ];
    }

    private function sh(string $value): string
    {
        return escapeshellarg($value);
    }
}
