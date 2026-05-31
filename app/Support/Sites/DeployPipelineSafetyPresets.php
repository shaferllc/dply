<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;

/**
 * One-click Laravel safety bundles for the deploy pipeline workspace.
 */
final class DeployPipelineSafetyPresets
{
    public const BUNDLE_LARAVEL_V1 = 'laravel_v1';

    /**
     * @return array<string, array{label: string, description: string, requires: string}>
     */
    public static function bundles(): array
    {
        return [
            self::BUNDLE_LARAVEL_V1 => [
                'label' => __('Laravel safety bundle'),
                'description' => __('Maintenance mode pair, migrate pretend, pre-migrate DB snapshot, then you add Migrate when ready.'),
                'requires' => 'laravel',
            ],
        ];
    }

    public static function visibleForSite(Site $site, string $bundleKey): bool
    {
        $bundle = self::bundles()[$bundleKey] ?? null;
        if ($bundle === null) {
            return false;
        }

        return DeployPipelinePalette::entryVisible($site, ['requires' => $bundle['requires']]);
    }

    /**
     * @return array{hooks_added: int, steps_added: int}
     */
    public function apply(string $bundleKey, SiteDeployPipeline $pipeline, Site $site): array
    {
        if ($bundleKey !== self::BUNDLE_LARAVEL_V1) {
            throw new \InvalidArgumentException(__('Unknown safety preset bundle.'));
        }

        if (! $site->isLaravelFrameworkDetected()) {
            throw new \InvalidArgumentException(__('Safety bundle requires a Laravel site.'));
        }

        $manager = app(SiteDeployPipelineManager::class);
        $hooksAdded = 0;
        $stepsAdded = 0;

        foreach ($this->maintenanceHooks() as $hook) {
            if ($this->hookExists($pipeline, $hook['anchor'], $hook['script'])) {
                continue;
            }
            $pipeline->hooks()->create([
                'site_id' => $site->id,
                'sort_order' => (int) ($pipeline->hooks()->max('sort_order') ?? 0) + 10,
                'phase' => $hook['phase'],
                'hook_kind' => SiteDeployHook::KIND_SHELL,
                'anchor' => $hook['anchor'],
                'anchor_step_id' => null,
                'label' => $hook['label'],
                'script' => $hook['script'],
                'timeout_seconds' => 120,
            ]);
            $hooksAdded++;
        }

        if (! $this->stepExists($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND)) {
            $manager->addStep(
                $pipeline,
                SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND,
                null,
                600,
                $this->releaseInsertIndex($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE),
                SiteDeployStep::PHASE_RELEASE,
            );
            $stepsAdded++;
        }

        if (! $this->stepExists($pipeline, SiteDeployStep::TYPE_CUSTOM, self::preMigrateBackupCommand())) {
            $manager->addStep(
                $pipeline,
                SiteDeployStep::TYPE_CUSTOM,
                self::preMigrateBackupCommand(),
                900,
                $this->releaseInsertIndex($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND),
                SiteDeployStep::PHASE_RELEASE,
            );
            $stepsAdded++;
        }

        return ['hooks_added' => $hooksAdded, 'steps_added' => $stepsAdded];
    }

    /**
     * @return list<array{anchor: string, phase: string, label: string, script: string}>
     */
    private function maintenanceHooks(): array
    {
        return [
            [
                'anchor' => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
                'phase' => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
                'label' => __('Maintenance down'),
                'script' => "php artisan down --no-interaction\n",
            ],
            [
                'anchor' => SiteDeployHook::ANCHOR_AFTER_ACTIVATE,
                'phase' => SiteDeployHook::PHASE_AFTER_ACTIVATE,
                'label' => __('Maintenance up'),
                'script' => "php artisan up --no-interaction\n",
            ],
        ];
    }

    public static function preMigrateBackupCommand(): string
    {
        return <<<'BASH'
set -euo pipefail
BACKUP_DIR="storage/app/dply-pre-migrate-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"
FILE="$BACKUP_DIR/pre-migrate.sql"
if command -v mysqldump >/dev/null 2>&1 && [ -f .env ]; then
  DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_HOST=$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  export MYSQL_PWD="$DB_PASSWORD"
  mysqldump -h "${DB_HOST:-127.0.0.1}" -u "$DB_USERNAME" "$DB_DATABASE" > "$FILE"
  echo "Pre-migrate backup written to $FILE"
elif command -v pg_dump >/dev/null 2>&1 && [ -f .env ]; then
  DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  DB_HOST=$(grep -E '^DB_HOST=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  PGPASSWORD=$(grep -E '^DB_PASSWORD=' .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
  export PGPASSWORD
  pg_dump -h "${DB_HOST:-127.0.0.1}" -U "$DB_USERNAME" "$DB_DATABASE" -f "$FILE"
  echo "Pre-migrate backup written to $FILE"
else
  echo "No mysqldump/pg_dump — schedule exports under Server → Databases → Backups or edit this step."
fi
BASH;
    }

    private function releaseInsertIndex(SiteDeployPipeline $pipeline, string $beforeType): int
    {
        $release = $pipeline->steps()
            ->where('phase', SiteDeployStep::PHASE_RELEASE)
            ->orderBy('sort_order')
            ->get();

        $index = 0;
        foreach ($release as $step) {
            if ($step->step_type === $beforeType) {
                return $index;
            }
            $index++;
        }

        return $release->count();
    }

    private function hookExists(SiteDeployPipeline $pipeline, string $anchor, string $script): bool
    {
        return $pipeline->hooks()
            ->where('anchor', $anchor)
            ->where('hook_kind', SiteDeployHook::KIND_SHELL)
            ->where('script', $script)
            ->exists();
    }

    private function stepExists(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand = null,
    ): bool {
        $query = $pipeline->steps()->where('step_type', $stepType);

        if ($customCommand !== null) {
            $query->where('custom_command', $customCommand);
        }

        return $query->exists();
    }
}
