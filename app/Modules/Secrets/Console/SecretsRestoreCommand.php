<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Console;

use App\Modules\Secrets\Services\RestoreTarget;
use App\Modules\Secrets\Services\Scope;
use App\Modules\Secrets\Services\SecretVault;
use Illuminate\Console\Command;

/**
 * List or restore escrowed secrets. Restore requires the OFFLINE age identity
 * (SECRET_VAULT_IDENTITY_PATH) — by design it cannot run on a normal prod box.
 */
class SecretsRestoreCommand extends Command
{
    protected $signature = 'secrets:restore
        {--scope=platform : platform | org-<id>}
        {--source=platform-env : platform-env | db-dump | ...}
        {--version=latest : "latest" or a full blob key}
        {--to=stdout : "stdout" or a filesystem path}
        {--force : overwrite an existing file at --to}
        {--list : list available versions and exit}';

    protected $description = 'Restore (or list) escrowed secrets from the vault.';

    public function handle(SecretVault $vault): int
    {
        $scope = Scope::fromKey((string) $this->option('scope'));
        $source = (string) $this->option('source');

        if ($this->option('list')) {
            $versions = $vault->listVersions($scope, $source);
            if ($versions === []) {
                $this->warn('No versions found.');

                return self::SUCCESS;
            }
            $this->table(
                ['Created', 'Key', 'Bytes', 'Stores'],
                array_map(fn ($r) => [$r->createdAt, $r->key, $r->byteLen ?? '?', implode(',', $r->stores)], $versions),
            );

            return self::SUCCESS;
        }

        $versionOpt = (string) $this->option('version');
        $ref = $versionOpt === 'latest'
            ? $vault->latest($scope, $source)
            : collect($vault->listVersions($scope, $source))->firstWhere('key', $versionOpt);

        if ($ref === null) {
            $this->error('No matching version found.');

            return self::FAILURE;
        }

        $toOpt = (string) $this->option('to');
        $target = $toOpt === 'stdout'
            ? RestoreTarget::stdout()
            : RestoreTarget::envFile($toOpt, (bool) $this->option('force'));

        try {
            $vault->restore($ref, $target);
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($toOpt !== 'stdout') {
            $this->info("Restored {$ref->key} → {$toOpt}");
        }

        return self::SUCCESS;
    }
}
