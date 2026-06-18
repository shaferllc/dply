<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Console;

use App\Models\Site;
use App\Modules\Secrets\Services\Scope;
use App\Modules\Secrets\Services\SecretVault;
use App\Modules\Secrets\Services\Sources\CriticalKeysSource;
use App\Modules\Secrets\Services\Sources\DbDumpSource;
use App\Modules\Secrets\Services\Sources\PlatformEnvSource;
use App\Modules\Secrets\Services\Sources\SiteEnvBundleSource;
use Illuminate\Console\Command;

/**
 * Escrow a secret source (default: the platform .env) to the configured vault
 * stores. App-native: scheduled daily via DplySchedule and runnable on demand.
 */
class SecretsEscrowCommand extends Command
{
    protected $signature = 'secrets:escrow
        {--scope=platform : platform | org-<id>}
        {--source=platform-env : platform-env | db-dump | critical-keys | site-env}
        {--site= : site id (required for --source=site-env); scope is derived from the site}
        {--force : escrow even if an identical blob already exists}';

    protected $description = 'Escrow secrets (age-encrypted) to the off-box vault stores.';

    public function handle(SecretVault $vault): int
    {
        $scope = Scope::fromKey((string) $this->option('scope'));
        $sourceKey = (string) $this->option('source');

        // A site backup ("site-env") is keyed to the site and scoped to its org —
        // the operator picks the site, not the scope, so we derive both here.
        if ($sourceKey === 'site-env') {
            $siteId = trim((string) $this->option('site'));
            $site = $siteId !== '' ? Site::find($siteId) : null;
            if ($site === null) {
                $this->error('--source=site-env requires a valid --site=<id>.');

                return self::FAILURE;
            }
            $source = new SiteEnvBundleSource($site);
            $scope = $source->scope();
        } else {
            $source = match ($sourceKey) {
                'platform-env' => new PlatformEnvSource(base_path('.env')),
                'db-dump' => new DbDumpSource,
                'critical-keys' => new CriticalKeysSource,
                default => null,
            };
        }

        if ($source === null) {
            $this->error("Unknown source: {$sourceKey} (expected platform-env, db-dump, critical-keys or site-env).");

            return self::FAILURE;
        }

        // Escrow-on-change for platform-env: skip if an identical blob exists.
        if (! $this->option('force') && $sourceKey === 'platform-env') {
            $envPath = base_path('.env');
            if (is_file($envPath)) {
                $sha = hash('sha256', (string) file_get_contents($envPath));
                if ($vault->hasVersionWithHash($scope, $sourceKey, $sha)) {
                    $this->info('Unchanged since last escrow — skipping. Use --force to escrow anyway.');

                    return self::SUCCESS;
                }
            }
        }

        try {
            $ref = $vault->escrow($source, $scope);
        } catch (\Throwable $e) {
            $this->error('Escrow failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Escrowed {$ref->key}");
        $this->line('  stores: '.implode(', ', $ref->stores));

        return self::SUCCESS;
    }
}
