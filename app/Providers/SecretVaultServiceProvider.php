<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Secrets\AgeEncryptor;
use App\Services\Secrets\SecretVault;
use App\Services\Secrets\Stores\GitOpsRepoVaultStore;
use App\Services\Secrets\Stores\ObjectStorageVaultStore;
use App\Services\Secrets\Stores\OnePasswordVaultStore;
use Illuminate\Support\ServiceProvider;

class SecretVaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Transient so each resolution gets a fresh UTC stamp for the blob key.
        $this->app->bind(SecretVault::class, function (): SecretVault {
            $cfg = (array) config('secret_vault');

            $age = new AgeEncryptor(
                ageBin: (string) ($cfg['age_bin'] ?? 'age'),
                recipientsPath: (string) ($cfg['recipients_path'] ?? ''),
                identityPath: $cfg['identity_path'] ?? null,
            );

            // Order = read preference (object primary, then git, then 1Password).
            $stores = [
                new ObjectStorageVaultStore((array) ($cfg['stores']['object'] ?? [])),
                new GitOpsRepoVaultStore((array) ($cfg['stores']['git'] ?? [])),
                new OnePasswordVaultStore((array) ($cfg['stores']['onepassword'] ?? [])),
            ];

            return new SecretVault(
                age: $age,
                stores: $stores,
                keyPrefix: trim((string) ($cfg['key_prefix'] ?? 'secret-vault/v1'), '/'),
                utcStamp: gmdate('Ymd\THis\Z'),
            );
        });
    }
}
