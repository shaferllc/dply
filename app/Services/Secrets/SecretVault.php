<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Contracts\VaultStore;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Escrows / lists / restores / verifies secret blobs. Interoperates with the
 * bash guards (deploy/secrets/*.sh) purely through the shared recipient, key
 * naming, and store layout — no shared code. See deploy/SECRETS.md.
 */
final class SecretVault
{
    /**
     * @param  array<int, VaultStore>  $stores  ordered by read preference (primary first)
     */
    public function __construct(
        private readonly AgeEncryptor $age,
        private readonly array $stores,
        private readonly string $keyPrefix,
        private readonly string $utcStamp,
    ) {}

    public function escrow(SecretSource $source, Scope $scope): VaultBlobRef
    {
        $plaintext = $source->gather($scope);
        $sha = hash('sha256', $plaintext);
        $key = sprintf('%s/%s/%s/%s-%s.age', $this->keyPrefix, $scope->key, $source->name(), $this->utcStamp, substr($sha, 0, 12));

        $ref = new VaultBlobRef(
            key: $key,
            scope: $scope->key,
            source: $source->name(),
            createdAt: $this->utcStamp,
            plaintextSha256: $sha,
            byteLen: strlen($plaintext),
        );

        $ciphertext = $this->age->encrypt($plaintext);
        $meta = $ref->toMeta('php');

        $written = [];
        foreach ($this->enabledStores() as $store) {
            try {
                $store->put($key, $ciphertext, $meta);
                $written[] = $store->name();
            } catch (\Throwable $e) {
                Log::warning('secret_vault.store_put_failed', ['store' => $store->name(), 'key' => $key, 'error' => $e->getMessage()]);
            }
        }

        if ($written === []) {
            throw new RuntimeException('escrow failed: no store accepted the blob.');
        }

        $ref->stores = $written;

        return $ref;
    }

    /**
     * Whether a blob with this plaintext hash already exists for the scope/source
     * (drives escrow-on-change so unchanged .env isn't re-escrowed every run).
     */
    public function hasVersionWithHash(Scope $scope, string $source, string $sha256): bool
    {
        $short = substr($sha256, 0, 12);
        foreach ($this->listVersions($scope, $source) as $ref) {
            if (str_ends_with($ref->key, "-{$short}.age")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, VaultBlobRef>
     */
    public function listVersions(Scope $scope, ?string $source = null): array
    {
        $prefix = $this->keyPrefix.'/'.$scope->key.'/'.($source !== null ? $source.'/' : '');

        /** @var array<string, VaultBlobRef> $byKey */
        $byKey = [];
        foreach ($this->enabledStores() as $store) {
            try {
                foreach ($store->list($prefix) as $entry) {
                    $key = $entry['key'];
                    if (isset($byKey[$key])) {
                        $byKey[$key]->stores[] = $store->name();

                        continue;
                    }
                    $ref = VaultBlobRef::fromMeta($key, $entry['meta'], [$store->name()]);
                    $byKey[$key] = $ref;
                }
            } catch (\Throwable $e) {
                Log::warning('secret_vault.store_list_failed', ['store' => $store->name(), 'error' => $e->getMessage()]);
            }
        }

        $refs = array_values($byKey);
        usort($refs, fn (VaultBlobRef $a, VaultBlobRef $b) => strcmp($b->createdAt, $a->createdAt));

        return $refs;
    }

    public function latest(Scope $scope, string $source): ?VaultBlobRef
    {
        return $this->listVersions($scope, $source)[0] ?? null;
    }

    public function restore(VaultBlobRef $ref, RestoreTarget $target): void
    {
        $plaintext = $this->fetchPlaintext($ref);

        match ($target->type) {
            RestoreTarget::TYPE_STDOUT => fwrite(STDOUT, $plaintext),
            RestoreTarget::TYPE_ENV_FILE => $this->writeEnvFile($target, $plaintext),
            default => throw new RuntimeException("Unknown restore target: {$target->type}"),
        };
    }

    /**
     * Fetch + decrypt + integrity-check a blob. Proves the ciphertext round-trips
     * under the offline identity and matches its recorded plaintext hash.
     *
     * @return array{ok: bool, message: string}
     */
    public function verify(VaultBlobRef $ref): array
    {
        try {
            $plaintext = $this->fetchPlaintext($ref);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($ref->plaintextSha256 !== null && hash('sha256', $plaintext) !== $ref->plaintextSha256) {
            return ['ok' => false, 'message' => 'sha256 mismatch — blob is corrupt or truncated.'];
        }

        return ['ok' => true, 'message' => 'decrypted + integrity verified ('.strlen($plaintext).' bytes).'];
    }

    private function fetchPlaintext(VaultBlobRef $ref): string
    {
        $lastError = null;
        foreach ($this->enabledStores() as $store) {
            try {
                $ciphertext = $store->get($ref->key);

                return $this->age->decrypt($ciphertext);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new RuntimeException('could not fetch/decrypt blob: '.($lastError?->getMessage() ?? 'no store had it'));
    }

    private function writeEnvFile(RestoreTarget $target, string $plaintext): void
    {
        $path = (string) $target->path;
        if (is_file($path) && ! $target->force) {
            throw new RuntimeException("refusing to overwrite existing {$path} without force.");
        }
        file_put_contents($path, $plaintext);
        @chmod($path, 0600);
    }

    /**
     * @return array<int, VaultStore>
     */
    private function enabledStores(): array
    {
        return array_values(array_filter($this->stores, fn (VaultStore $s) => $s->enabled()));
    }
}
