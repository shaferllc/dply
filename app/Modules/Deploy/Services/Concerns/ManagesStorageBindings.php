<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\ObjectStorageCredential;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach / provision the `storage` binding type (S3-compatible object storage)
 * and build its AWS_* connection env.
 *
 * @property-read ObjectStorageBucketProvisioner $bucketProvisioner
 */
trait ManagesStorageBindings
{
    /**
     * @param  array<string, mixed> $params
     */
    private function attachStorage(Site $site, array $params): SiteBinding
    {
        $providers = (array) config('object_storage.providers', []);
        $provider = strtolower(trim((string) ($params['provider'] ?? 'aws_s3')));
        if (! array_key_exists($provider, $providers)) {
            throw new InvalidArgumentException(__('Unsupported object storage provider.'));
        }

        $bucket = trim((string) ($params['bucket'] ?? ''));

        $creds = $this->resolveStorageCredentials($site, $provider, $params);
        $key = $creds['key'];
        $secret = $creds['secret'];
        if ($bucket === '' || $key === '' || $secret === '') {
            throw new InvalidArgumentException(__('Bucket, access key, and secret are required.'));
        }

        $region = $creds['region'];

        // Endpoint resolution: an explicit endpoint always wins; otherwise derive
        // it from the provider's template (needs a region). AWS leaves the
        // template empty so AWS_ENDPOINT stays unset and the SDK picks the
        // regional endpoint itself. Custom providers must supply the endpoint.
        $endpoint = $creds['endpoint'];
        $template = (string) ($providers[$provider]['endpoint_template'] ?? '');
        if ($endpoint === '' && $template !== '' && $region !== '') {
            $endpoint = str_replace('{region}', $region, $template);
        }

        if ($provider === 'custom_s3' && $endpoint === '') {
            throw new InvalidArgumentException(__('Custom S3 storage needs an endpoint.'));
        }

        $disk = $this->resolveStorageDisk($site, $params);

        $binding = $this->persist($site, 'storage', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $disk,
            'target_type' => 'object_storage',
            'target_id' => null,
            'injected_env' => $this->storageEnv($bucket, $key, $secret, $region, $endpoint, (string) ($params['url'] ?? ''), $disk),
            'config' => array_filter([
                'disk' => $disk,
                'bucket' => $bucket,
                'provider' => $provider,
                'region' => $region ?: null,
            ]),
        ], ['site_id', 'type', 'name']);

        $this->maybeSaveStorageCredential($site, $provider, $params, $key, $secret, $region, $endpoint);

        return $binding;
    }

    /**
     * Provision a brand-new bucket on a provisionable S3 provider (DigitalOcean
     * Spaces / Hetzner) with the operator's storage keys, then wire it like an
     * attach. Mirrors {@see provisionDatabase}: a creation failure is recorded
     * on the binding (status=error, last_error) so it surfaces inline and can
     * be retried, then re-thrown for the toast.
     *
     * @param  array<string, mixed> $params
     */
    private function provisionBucket(Site $site, array $params): SiteBinding
    {
        $providers = (array) config('object_storage.providers', []);
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        $meta = $providers[$provider] ?? null;
        if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
            throw new InvalidArgumentException(__('This provider does not support provisioning a bucket yet.'));
        }

        $bucket = strtolower(trim((string) ($params['bucket'] ?? '')));

        // S3 bucket names: DNS-compliant, 3–63 chars, lowercase, no underscores
        // (so virtual-hosted URLs resolve). Validate before minting any keys.
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new InvalidArgumentException(__('Bucket name must be 3–63 characters: lowercase letters, numbers, dots, or hyphens.'));
        }

        // Two ways to get the S3 keys: have dply mint them via the provider's
        // cloud API token (DigitalOcean Spaces), or use saved/typed keys
        // (Hetzner, or DO when the operator prefers their own keys).
        $apiManaged = (bool) ($meta['api_managed'] ?? false);
        $keySource = (string) ($params['key_source'] ?? ($apiManaged ? 'api' : 'manual'));
        $autoMinted = $apiManaged && $keySource === 'api';

        if ($autoMinted) {
            $minted = $this->mintApiManagedKeys($site, $provider, $bucket, $params);
            $key = $minted['key'];
            $secret = $minted['secret'];
            $region = trim((string) ($params['region'] ?? ''));
        } else {
            $creds = $this->resolveStorageCredentials($site, $provider, $params);
            $key = $creds['key'];
            $secret = $creds['secret'];
            $region = $creds['region'];

            if ($key === '' || $secret === '') {
                throw new InvalidArgumentException(__('Storage access key and secret are required to provision a bucket.'));
            }
        }

        // Nothing exists on the provider until CreateBucket succeeds, so a
        // failure just bubbles up to the toast — unlike provisionDatabase, we
        // don't persist an error row, which would clobber an existing working
        // storage binding (one row per site+type).
        // Auto-minted keys (DO Spaces) aren't active on the S3 gateway for a few
        // seconds, so let the provisioner retry the rejection codes in that case.
        $disk = $this->resolveStorageDisk($site, $params);

        $result = $this->bucketProvisioner->create($provider, $region, $key, $secret, $bucket, awaitKeyPropagation: $autoMinted);
        $endpoint = (string) ($result['endpoint'] ?? '');

        $binding = $this->persist($site, 'storage', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $disk,
            'target_type' => 'object_storage',
            'target_id' => null,
            'injected_env' => $this->storageEnv($bucket, $key, $secret, $region, $endpoint, (string) ($params['url'] ?? ''), $disk),
            'config' => array_filter([
                'disk' => $disk,
                'bucket' => $bucket,
                'provider' => $provider,
                'region' => $region ?: null,
                'api_managed' => $autoMinted ?: null,
            ]),
            'last_error' => null,
        ], ['site_id', 'type', 'name']);

        // API-minted keys aren't from the form, so there's nothing to "save for
        // reuse" — the binding already carries them.
        if (! $autoMinted) {
            $this->maybeSaveStorageCredential($site, $provider, $params, $key, $secret, $region, $endpoint);
        }

        return $binding;
    }

    /**
     * Mint S3 keys for an api_managed provider (DigitalOcean Spaces) via the
     * org's cloud API token, so the operator never pastes keys.
     *
     * @param  array<string, mixed> $params
     * @return array{key: string, secret: string}
     */
    private function mintApiManagedKeys(Site $site, string $provider, string $bucket, array $params): array
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);
        $apiProvider = (string) ($meta['api_provider'] ?? '');
        if (! (bool) ($meta['api_managed'] ?? false) || $apiProvider === '') {
            throw new InvalidArgumentException(__('This provider cannot create keys automatically.'));
        }

        $credentialId = trim((string) ($params['provider_credential_id'] ?? ''));
        $query = ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', $apiProvider);
        $credential = $credentialId !== ''
            ? (clone $query)->whereKey($credentialId)->first()
            : (clone $query)->orderBy('created_at')->first();

        if (! $credential instanceof ProviderCredential) {
            throw new InvalidArgumentException(__('Connect a :provider API token under Credentials to create keys automatically, or switch to entering keys manually.', ['provider' => (string) ($meta['label'] ?? $apiProvider)]));
        }

        return match ($apiProvider) {
            'digitalocean' => $this->mintDigitalOceanSpacesKey($credential, $bucket),
            default => throw new InvalidArgumentException(__('Automatic key creation is not supported for this provider yet.')),
        };
    }

    /**
     * @return array{key: string, secret: string}
     */
    private function mintDigitalOceanSpacesKey(ProviderCredential $credential, string $bucket): array
    {
        // Full-access key so it can create the bucket — createSpacesKey() turns
        // an empty grant list into an explicit full-access grant (a DO Spaces key
        // with NO grants has NO access). DO returns the secret only at creation
        // time; it lives on the binding's encrypted env after.
        $minted = (new DigitalOceanService($credential))->createSpacesKey('dply-'.$bucket, []);

        return ['key' => $minted['access_key'], 'secret' => $minted['secret_key']];
    }

    /**
     * Resolve the S3 keys + region/endpoint for a storage binding: either a
     * saved {@see ObjectStorageCredential} (chosen via $params['credential_id'],
     * scoped to the site's org and provider) or the keys typed into the form.
     * Form region/endpoint always win over the saved credential's stored
     * defaults so the operator's picks aren't silently overridden.
     *
     * @param  array<string, mixed> $params
     * @return array{key: string, secret: string, region: string, endpoint: string}
     */
    private function resolveStorageCredentials(Site $site, string $provider, array $params): array
    {
        $formRegion = trim((string) ($params['region'] ?? ''));
        $formEndpoint = trim((string) ($params['endpoint'] ?? ''));

        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = ObjectStorageCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof ObjectStorageCredential) {
                throw new InvalidArgumentException(__('That saved storage credential is no longer available.'));
            }

            return [
                'key' => (string) $cred->access_key_id,
                'secret' => (string) $cred->secret_access_key,
                'region' => $formRegion !== '' ? $formRegion : (string) ($cred->region ?? ''),
                'endpoint' => $formEndpoint !== '' ? $formEndpoint : (string) ($cred->endpoint ?? ''),
            ];
        }

        return [
            'key' => trim((string) ($params['access_key_id'] ?? '')),
            'secret' => trim((string) ($params['secret_access_key'] ?? '')),
            'region' => $formRegion,
            'endpoint' => $formEndpoint,
        ];
    }

    /**
     * Persist the entered keys as a reusable {@see ObjectStorageCredential} when
     * the operator ticked "save for reuse". No-op when reusing an existing
     * saved credential or when saving wasn't requested.
     *
     * @param  array<string, mixed> $params
     */
    private function maybeSaveStorageCredential(Site $site, string $provider, array $params, string $key, string $secret, string $region, string $endpoint): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        // Reusing a saved credential — nothing new to store.
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($key === '' || $secret === '') {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $label = (string) (config('object_storage.providers.'.$provider.'.label') ?? $provider);
            $name = $label.' '.__('keys');
        }

        ObjectStorageCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'access_key_id' => $key,
            'secret_access_key' => $secret,
            'region' => $region !== '' ? $region : null,
            'endpoint' => $endpoint !== '' ? $endpoint : null,
        ]);
    }

    /**
     * Build the S3 connection variables a storage binding injects at deploy.
     *
     * A site can attach several buckets, each its own Laravel filesystem disk.
     * The PRIMARY disk (`s3`) keeps the bare, framework-default keyset
     * (FILESYSTEM_DISK=s3 + AWS_*) so the common single-bucket case needs no app
     * changes. Every ADDITIONAL disk namespaces its keys by the uppercased disk
     * slug (AWS_<DISK>_*) and deliberately omits FILESYSTEM_DISK so two buckets
     * never collide on the same env keys when merged into the deploy .env.
     *
     * @return array<string, string>
     */
    private function storageEnv(string $bucket, string $key, string $secret, string $region, string $endpoint, string $url, string $disk = 's3'): array
    {
        $url = trim($url);

        if ($this->storageDiskIsPrimary($disk)) {
            return array_filter([
                'FILESYSTEM_DISK' => 's3',
                'AWS_BUCKET' => $bucket,
                'AWS_ACCESS_KEY_ID' => $key,
                'AWS_SECRET_ACCESS_KEY' => $secret,
                'AWS_DEFAULT_REGION' => $region !== '' ? $region : null,
                'AWS_URL' => $url !== '' ? $url : null,
                'AWS_ENDPOINT' => $endpoint !== '' ? $endpoint : null,
            ], fn ($v) => $v !== null);
        }

        $p = 'AWS_'.strtoupper($disk).'_';

        return array_filter([
            $p.'BUCKET' => $bucket,
            $p.'ACCESS_KEY_ID' => $key,
            $p.'SECRET_ACCESS_KEY' => $secret,
            $p.'DEFAULT_REGION' => $region !== '' ? $region : null,
            $p.'URL' => $url !== '' ? $url : null,
            $p.'ENDPOINT' => $endpoint !== '' ? $endpoint : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Normalize an operator-entered disk name into a Laravel-disk-safe slug:
     * lowercase, alphanumerics + underscores, no leading digit/underscore. Blank
     * input defaults to the primary `s3` disk.
     */
    public function storageDiskSlug(mixed $name): string
    {
        $slug = Str::of((string) $name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        $slug = ltrim($slug, '0123456789_');

        return $slug === '' ? 's3' : $slug;
    }

    private function storageDiskIsPrimary(string $disk): bool
    {
        return $disk === 's3';
    }

    /**
     * Resolve + validate the disk slug for a storage binding. Each disk is unique
     * per site (two buckets can't share a disk, or their env would collide). When
     * editing an existing binding ($params['binding_id']), a same-slug match on
     * that row is allowed (it's an update); any other collision is rejected.
     *
     * @param  array<string, mixed> $params
     */
    private function resolveStorageDisk(Site $site, array $params): string
    {
        $disk = $this->storageDiskSlug($params['disk'] ?? '');
        $editingId = trim((string) ($params['binding_id'] ?? ''));

        $clash = $site->bindings()
            ->where('type', 'storage')
            ->where('name', $disk)
            ->when($editingId !== '', fn ($q) => $q->whereKeyNot($editingId))
            ->exists();

        if ($clash) {
            throw new InvalidArgumentException($this->storageDiskIsPrimary($disk)
                ? __('A default object-storage disk (s3) is already attached. Give this bucket a different disk name to attach it alongside.')
                : __('A storage disk named ":disk" is already attached to this site.', ['disk' => $disk]));
        }

        return $disk;
    }

    /**
     * The `config/filesystems.php` disk array an operator must paste into their
     * app for a NON-primary bucket (the primary `s3` disk ships with Laravel, so
     * it needs none). Mirrors the mail binding's config-snippet pattern.
     */
    public function storageFilesystemSnippet(string $disk): string
    {
        if ($this->storageDiskIsPrimary($disk)) {
            return '';
        }

        $p = 'AWS_'.strtoupper($disk).'_';

        return <<<PHP
        '{$disk}' => [
            'driver' => 's3',
            'key' => env('{$p}ACCESS_KEY_ID'),
            'secret' => env('{$p}SECRET_ACCESS_KEY'),
            'region' => env('{$p}DEFAULT_REGION'),
            'bucket' => env('{$p}BUCKET'),
            'url' => env('{$p}URL'),
            'endpoint' => env('{$p}ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
        PHP;
    }
}
