<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
 * through Dotenv like any other config — `Storage::disk('s3')` just works.
 */
class ServerlessAppBucketProvisioner
{
    public function __construct(
        private readonly ObjectStorageBucketProvisioner $buckets,
        private readonly ServerlessEnvironmentPreparer $environment,
    ) {}

    public const PROVIDER = 'digitalocean_spaces';

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

    private function provision(Site $site, ?string $region): SiteBinding
    {
        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            throw new RuntimeException('No platform DigitalOcean token is configured, so dply cannot provision an app bucket.');
        }

        $region = trim($region ?? (string) config('serverless.assets.app_buckets.region', 'nyc3'));
        $bucket = $this->bucketName($site);
        $do = new DigitalOceanService($token);

        // Two keys, deliberately. The platform key (no grants => full access)
        // creates the bucket; the app then gets a SECOND key granted to just
        // that bucket, which is the credential the customer's code runs with.
        $platform = $do->createSpacesKey('dply-platform-provision-'.$bucket);

        $created = $this->buckets->create(
            self::PROVIDER,
            $region,
            $platform['access_key'],
            $platform['secret_key'],
            $bucket,
            awaitKeyPropagation: true,
        );

        $scoped = $do->createSpacesKey('dply-fn-'.$bucket, [
            ['bucket' => $bucket, 'permission' => 'readwrite'],
        ]);

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
                    // Marks this as platform-provisioned so teardown knows it
                    // owns the bucket and may delete it.
                    'managed_by' => 'serverless_app_bucket',
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
        $prefix = 'AWS_'.strtoupper($this->diskName()).'_';

        return array_filter([
            $prefix.'BUCKET' => $bucket,
            $prefix.'ACCESS_KEY_ID' => $key['access_key'],
            $prefix.'SECRET_ACCESS_KEY' => $key['secret_key'],
            $prefix.'DEFAULT_REGION' => $region,
            $prefix.'ENDPOINT' => $endpoint,
        ], static fn (string $value): bool => $value !== '');
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
}
