<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Gives a managed function its own object-storage bucket and wires it into the
 * app's environment, the way an attached storage resource already works for VM
 * and Edge sites.
 *
 * ## Why this is NOT the asset bucket
 *
 * Published front-end builds live in one shared, platform-owned bucket
 * ({@see ServerlessAssetPublisher}), written only by the control plane at
 * deploy time and read only through the CDN. Its credentials must never reach
 * a customer's app: DigitalOcean Spaces grants are scoped per BUCKET, not per
 * prefix, so a key that can write one site's prefix can read and overwrite
 * every other site's. Injecting it would be a cross-tenant breach.
 *
 * So an app that wants to store its own files gets its own bucket, with a key
 * granted to that bucket alone. Extra buckets cost nothing — a Spaces
 * subscription is a single $5/mo charge covering many buckets, with the
 * storage and transfer allowances shared across them — so isolation here is
 * free, while sharing would be dangerous.
 *
 * ## How it reaches the app
 *
 * Same channel a provisioned database uses: the connection variables are
 * persisted on a `storage` {@see SiteBinding} (encrypted) and merged into the
 * function's managed environment via
 * {@see ServerlessEnvironmentPreparer::mergeKeys()}. The deploy writes that
 * environment into the artifact's `.env` before packaging, so the app reads it
 * through Dotenv like any other config — `Storage::disk('uploads')` just works.
 *
 * ## Two keys, one of which does not survive the call
 *
 * Creating a bucket needs a full-access key, because a Spaces key can only be
 * granted to buckets that already exist. That key is minted for the duration
 * of provisioning and revoked in a `finally` — including when provisioning
 * fails. Nothing persists it: a per-site, full-access, never-expiring
 * credential is exactly the thing this module exists to avoid handing out.
 * The credential the app keeps is the second key, granted `readwrite` on its
 * own bucket and nothing else.
 */
class ServerlessAppBucketProvisioner
{
    public function __construct(
        private readonly ObjectStorageBucketProvisioner $buckets,
        private readonly ServerlessEnvironmentPreparer $environment,
    ) {}

    public const PROVIDER = 'digitalocean_spaces';

    /** Marks a binding as one dply provisioned, so teardown may delete the bucket behind it. */
    public const MANAGED_BY = 'serverless_app_bucket';

    /**
     * Ensure this site has an app bucket, and that its connection variables
     * are in the function's environment. Idempotent: an existing binding is
     * re-injected rather than reprovisioned, so calling it on every deploy is
     * safe and self-healing.
     */
    public function ensure(Site $site, ?string $region = null): SiteBinding
    {
        $existing = $this->existingBinding($site);
        if ($existing instanceof SiteBinding) {
            $this->healUploadPolicy($existing);
            $this->inject($site, $existing);

            return $existing;
        }

        return $this->provision($site, $region);
    }

    /**
     * The site's dply-provisioned app bucket binding, if it has one. Matched
     * on the reserved disk name so an operator's own attached storage bindings
     * are never mistaken for this one.
     */
    public function existingBinding(Site $site): ?SiteBinding
    {
        return $site->bindings()
            ->where('type', 'storage')
            ->where('name', $this->diskName())
            ->first();
    }

    /**
     * Destroy a site's app bucket and revoke the key that reached it.
     *
     * Returns false when there is nothing dply owns to destroy. Called from
     * function teardown: without it, deleting a site leaves a bucket holding
     * customer data and a live Spaces key pointed at it, both still billing
     * and neither reachable from dply any more.
     *
     * The bucket is emptied first ({@see ObjectStorageBucketProvisioner::delete()}),
     * so this is destructive and deliberately only ever runs for a binding
     * dply provisioned.
     */
    public function destroy(Site $site): bool
    {
        $binding = $this->existingBinding($site);
        if (! $binding instanceof SiteBinding) {
            return false;
        }

        // An operator-attached bucket that happens to use the reserved disk
        // name is theirs, not ours — unwire it, never delete it.
        if ((string) (data_get($binding->config, 'managed_by') ?? '') !== self::MANAGED_BY) {
            $binding->delete();

            return false;
        }

        $bucket = trim((string) (data_get($binding->config, 'bucket') ?? ''));
        $region = trim((string) (data_get($binding->config, 'region') ?? ''));
        if ($bucket === '' || $region === '') {
            $binding->delete();

            return false;
        }

        $do = new DigitalOceanService($this->platformToken());
        $platform = $do->createSpacesKey('dply-platform-teardown-'.$bucket);

        try {
            $this->buckets->delete(
                self::PROVIDER,
                $region,
                $platform['access_key'],
                $platform['secret_key'],
                $bucket,
                awaitKeyPropagation: true,
            );
        } finally {
            $this->revokeKey($do, $platform['access_key'], $bucket);
        }

        $this->revokeKey($do, $this->appKeyId($binding), $bucket);
        $binding->delete();

        Log::info('serverless.app_bucket.destroyed', [
            'site_id' => $site->id,
            'bucket' => $bucket,
        ]);

        return true;
    }

    /**
     * Bytes this site's app bucket is holding, or null when it has none.
     *
     * Measured by listing, the same way {@see ServerlessAssetGarbageCollector}
     * measures published assets — Spaces exposes no per-bucket usage API and
     * charges nothing per request, so a LIST is both the cheapest and the only
     * accurate answer. Uses the app's own key: a bucket-scoped `readwrite`
     * grant can list its own bucket, so measuring needs no platform key.
     */
    public function storageBytes(Site $site): ?int
    {
        $binding = $this->existingBinding($site);
        if (! $binding instanceof SiteBinding) {
            return null;
        }

        $bucket = trim((string) (data_get($binding->config, 'bucket') ?? ''));
        $region = trim((string) (data_get($binding->config, 'region') ?? ''));
        $env = $binding->connectionEnv();
        $prefix = $this->envPrefix();
        $key = trim((string) ($env[$prefix.'ACCESS_KEY_ID'] ?? ''));
        $secret = trim((string) ($env[$prefix.'SECRET_ACCESS_KEY'] ?? ''));

        if ($bucket === '' || $region === '' || $key === '' || $secret === '') {
            return null;
        }

        return $this->buckets->usageBytes(self::PROVIDER, $region, $key, $secret, $bucket);
    }

    private function provision(Site $site, ?string $region): SiteBinding
    {
        $region = trim($region ?? (string) config('serverless.assets.app_buckets.region', 'nyc3'));
        $bucket = $this->bucketName($site);
        $do = new DigitalOceanService($this->platformToken());

        // Two keys, deliberately. The platform key (no grants => full access)
        // creates the bucket and applies its policy; the app then gets a
        // SECOND key granted to just that bucket, which is the credential the
        // customer's code runs with. The platform key is revoked below,
        // whatever happens in between.
        $platform = $do->createSpacesKey('dply-platform-provision-'.$bucket);

        try {
            $created = $this->buckets->create(
                self::PROVIDER,
                $region,
                $platform['access_key'],
                $platform['secret_key'],
                $bucket,
                awaitKeyPropagation: true,
            );

            $configured = $this->applyUploadPolicy($bucket, $region, $platform['access_key'], $platform['secret_key']);

            $scoped = $do->createSpacesKey('dply-fn-'.$bucket, [
                ['bucket' => $bucket, 'permission' => 'readwrite'],
            ]);
        } finally {
            $this->revokeKey($do, $platform['access_key'], $bucket);
        }

        $binding = SiteBinding::query()->updateOrCreate(
            ['site_id' => $site->id, 'type' => 'storage', 'name' => $this->diskName()],
            [
                'mode' => 'provision_new',
                'status' => SiteBinding::STATUS_CONFIGURED,
                'target_type' => 'object_storage',
                'target_id' => null,
                'injected_env' => $this->connectionEnv($bucket, $scoped, $region, $created['endpoint']),
                'config' => [
                    'disk' => $this->diskName(),
                    'provider' => self::PROVIDER,
                    'bucket' => $bucket,
                    'region' => $region,
                    'endpoint' => $created['endpoint'],
                    // The app key's public half, kept out of the encrypted
                    // env blob so teardown can revoke it without decrypting
                    // the secret it is paired with.
                    'key_id' => $scoped['access_key'],
                    'upload_policy_at' => $configured ? now()->toIso8601String() : null,
                    // Marks this as platform-provisioned so teardown knows it
                    // owns the bucket and may delete it.
                    'managed_by' => self::MANAGED_BY,
                ],
            ],
        );

        Log::info('serverless.app_bucket.provisioned', [
            'site_id' => $site->id,
            'bucket' => $bucket,
            'region' => $region,
        ]);

        $this->inject($site, $binding);

        return $binding;
    }

    /**
     * CORS + `tmp/` lifecycle, without which browser-direct uploads cannot
     * work at all. Non-fatal: a bucket missing its policy still serves every
     * server-side read and write, and losing a deploy over it would be the
     * worse trade. {@see healUploadPolicy()} retries on the next deploy.
     */
    private function applyUploadPolicy(string $bucket, string $region, string $accessKey, string $secret): bool
    {
        try {
            $this->buckets->applyUploadPolicy(
                self::PROVIDER,
                $region,
                $accessKey,
                $secret,
                $bucket,
                $this->tmpPrefix(),
                max(1, (int) config('serverless.assets.app_buckets.tmp_expiry_days', 1)),
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('serverless.app_bucket.upload_policy_failed', [
                'bucket' => $bucket,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Apply the upload policy to a bucket that does not have it — one
     * provisioned before the policy existed, or one whose policy call failed.
     *
     * Stamped rather than re-applied every deploy, so the steady state costs
     * no provider calls. Needs a full-access key, because bucket-level
     * configuration is outside a `readwrite` grant.
     */
    private function healUploadPolicy(SiteBinding $binding): void
    {
        $config = is_array($binding->config) ? $binding->config : [];
        if (($config['managed_by'] ?? null) !== self::MANAGED_BY || ! empty($config['upload_policy_at'])) {
            return;
        }

        $bucket = trim((string) ($config['bucket'] ?? ''));
        $region = trim((string) ($config['region'] ?? ''));
        if ($bucket === '' || $region === '') {
            return;
        }

        try {
            $do = new DigitalOceanService($this->platformToken());
            $platform = $do->createSpacesKey('dply-platform-policy-'.$bucket);
        } catch (Throwable $e) {
            Log::warning('serverless.app_bucket.upload_policy_failed', [
                'bucket' => $bucket,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            $applied = $this->applyUploadPolicy($bucket, $region, $platform['access_key'], $platform['secret_key']);
        } finally {
            $this->revokeKey($do, $platform['access_key'], $bucket);
        }

        if ($applied) {
            $config['upload_policy_at'] = now()->toIso8601String();
            $binding->forceFill(['config' => $config])->save();
        }
    }

    /**
     * Revoke a key, best-effort. A failure here leaks a credential rather than
     * breaking a deploy, so it is loud in the log and silent to the caller —
     * but it is never skipped, which is the point of calling it from
     * `finally`.
     */
    private function revokeKey(DigitalOceanService $do, string $accessKey, string $bucket): void
    {
        if (trim($accessKey) === '') {
            return;
        }

        try {
            $do->deleteSpacesKey($accessKey);
        } catch (Throwable $e) {
            Log::error('serverless.app_bucket.key_revoke_failed', [
                'bucket' => $bucket,
                'access_key' => $accessKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The app key's id, from the binding config, falling back to the injected
     * env for bindings written before the id was stored alongside it.
     */
    private function appKeyId(SiteBinding $binding): string
    {
        $fromConfig = trim((string) (data_get($binding->config, 'key_id') ?? ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return trim((string) ($binding->connectionEnv()[$this->envPrefix().'ACCESS_KEY_ID'] ?? ''));
    }

    /**
     * Merge the binding's connection variables into the function's managed
     * environment. The deploy bundles that env into the artifact, so the app
     * picks the bucket up on its next deploy without the user editing anything.
     */
    private function inject(Site $site, SiteBinding $binding): void
    {
        $env = $binding->connectionEnv();
        if ($env === []) {
            return;
        }

        $this->environment->mergeKeys($site, $env);
    }

    /**
     * Laravel-shaped S3 connection block, matching what an attached storage
     * binding emits for a non-primary disk so app code and docs stay uniform.
     *
     * @param  array{access_key: string, secret_key: string}  $key
     * @return array<string, string>
     */
    private function connectionEnv(string $bucket, array $key, string $region, string $endpoint): array
    {
        $prefix = $this->envPrefix();

        return array_filter([
            $prefix.'BUCKET' => $bucket,
            $prefix.'ACCESS_KEY_ID' => $key['access_key'],
            $prefix.'SECRET_ACCESS_KEY' => $key['secret_key'],
            $prefix.'DEFAULT_REGION' => $region,
            $prefix.'ENDPOINT' => $endpoint,
        ], static fn (string $value): bool => $value !== '');
    }

    private function envPrefix(): string
    {
        return 'AWS_'.strtoupper($this->diskName()).'_';
    }

    /**
     * Bucket names are globally unique per provider, so key on the site's
     * asset label — already globally unique and already the identifier the
     * hostname and the asset prefix use.
     */
    private function bucketName(Site $site): string
    {
        $site->ensureServerlessProxySlug();
        $label = ServerlessAssetHost::label($site);
        if ($label === null) {
            throw new RuntimeException('Cannot name an app bucket before the function has a slug.');
        }

        $prefix = trim((string) config('serverless.assets.app_buckets.name_prefix', 'dply-fn'), '-');

        // Spaces caps bucket names at 63 characters, same as a DNS label.
        return substr($prefix.'-'.$label, 0, 63);
    }

    /**
     * Reserved Laravel disk name for the platform-provisioned app bucket.
     * Deliberately not `s3`: the primary disk stays the operator's to attach,
     * so dply never silently redirects FILESYSTEM_DISK.
     */
    private function diskName(): string
    {
        return trim((string) config('serverless.assets.app_buckets.disk', 'uploads'));
    }

    /**
     * Prefix browser uploads land in before the app claims them, and the one
     * the lifecycle rule expires. Documented in docs/SERVERLESS_STORAGE.md —
     * changing it strands whatever is already staged.
     */
    private function tmpPrefix(): string
    {
        $prefix = trim((string) config('serverless.assets.app_buckets.tmp_prefix', 'tmp/'));

        return $prefix === '' ? 'tmp/' : rtrim($prefix, '/').'/';
    }

    private function platformToken(): string
    {
        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            throw new RuntimeException('No platform DigitalOcean token is configured, so dply cannot manage an app bucket.');
        }

        return $token;
    }
}
