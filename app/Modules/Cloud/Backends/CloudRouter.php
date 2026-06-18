<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Support\Servers\FakeCloudProvision;

/**
 * Maps a Site's container_backend column → concrete CloudBackend
 * implementation, and finds the right ProviderCredential to use.
 *
 * This is the seam between "the Site model" and "AWS/DO SDK
 * code" — the rest of the application talks to CloudRouter and
 * never imports a backend or service class directly.
 */
class CloudRouter
{
    /**
     * @return array<string, class-string<CloudBackend>>
     */
    public static function backends(): array
    {
        return [
            'digitalocean_app_platform' => DigitalOceanAppPlatformBackend::class,
            'aws_app_runner' => AwsAppRunnerBackend::class,
        ];
    }

    /**
     * Map a backend slug (the value stored in sites.container_backend) to
     * the provider_credentials.provider key that holds the API token.
     *
     * DO App Platform and DO compute share one PAT, so both use 'digitalocean'.
     * AWS App Runner uses its own access-key credential row for now.
     */
    public static function credentialProviderFor(string $backend): string
    {
        return match ($backend) {
            'digitalocean_app_platform' => 'digitalocean',
            default => $backend,
        };
    }

    /**
     * Inverse of credentialProviderFor — credential provider keys
     * that satisfy any cloud backend. Used by existence checks and
     * UI links that need to know "which credentials count as connected".
     *
     * @return list<string>
     */
    public static function credentialProviderKeys(): array
    {
        $keys = array_values(array_unique(array_map(
            fn (string $backend) => self::credentialProviderFor($backend),
            array_keys(self::backends()),
        )));

        return $keys;
    }

    public static function backendFor(Site $site): ?CloudBackend
    {
        $key = $site->container_backend;
        $map = self::backends();
        if (! is_string($key) || ! isset($map[$key])) {
            return null;
        }

        // Fake-cloud mode: when no real persisted credential exists for
        // the site's backend, route everything through FakeCloudBackend
        // so dev installs (and tests that don't fake out HTTP) can
        // reach end-to-end states without a real DO/AWS account.
        if (FakeCloudProvision::enabled() && ! self::hasPersistedCredential($site)) {
            return new FakeCloudBackend($key);
        }

        $class = $map[$key];

        return new $class;
    }

    private static function hasPersistedCredential(Site $site): bool
    {
        if (! is_string($site->container_backend) || $site->container_backend === '') {
            return false;
        }

        return ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', self::credentialProviderFor($site->container_backend))
            ->exists();
    }

    /**
     * Find the ProviderCredential that should be used to talk to
     * the site's container backend. Prefers a credential explicitly
     * tagged via meta['container']['credential_id']; falls back to
     * the first credential in the org with the matching provider.
     */
    public static function credentialFor(Site $site): ?ProviderCredential
    {
        $meta = ($site->meta );
        $explicit = $meta['container']['credential_id'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            $cred = ProviderCredential::query()->find($explicit);
            if ($cred && $cred->organization_id === $site->organization_id) {
                return $cred;
            }
        }

        if (! is_string($site->container_backend) || $site->container_backend === '') {
            return null;
        }

        $resolved = ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', self::credentialProviderFor($site->container_backend))
            ->orderBy('created_at')
            ->first();

        // In fake-cloud mode there's no real credential to find.
        // Synthesize an unsaved placeholder so jobs that pass it
        // through to FakeCloudBackend (which ignores it) still work.
        if ($resolved === null && FakeCloudProvision::enabled()) {
            $placeholder = new ProviderCredential;
            $placeholder->organization_id = $site->organization_id;
            $placeholder->provider = self::credentialProviderFor((string) $site->container_backend);
            $placeholder->name = 'fake-cloud (no real credential)';
            $placeholder->credentials = [];

            return $placeholder;
        }

        return $resolved;
    }

    /**
     * Pick the cheapest/closest backend that has a credential
     * connected for the org. Used when the operator opts into
     * "let dply choose" instead of explicitly picking DO vs AWS.
     *
     * Default ordering today: DO App Platform first (cheaper for
     * small workloads), App Runner second. Future iterations can
     * factor in region affinity, headroom, or cost forecasting.
     */
    public static function pickAutoBackend(string $organizationId): ?string
    {
        $preferred = ['digitalocean_app_platform', 'aws_app_runner'];
        $availableCredentialProviders = ProviderCredential::query()
            ->where('organization_id', $organizationId)
            ->whereIn('provider', self::credentialProviderKeys())
            ->pluck('provider')
            ->unique()
            ->all();

        foreach ($preferred as $backend) {
            $credProvider = self::credentialProviderFor($backend);
            if (in_array($credProvider, $availableCredentialProviders, true)) {
                return $backend;
            }
        }

        // Fake-cloud mode: pick a default so the create flow keeps
        // working without a real cloud credential connected. DO is
        // still preferred so the dev install matches the prod default.
        if (FakeCloudProvision::enabled()) {
            return 'digitalocean_app_platform';
        }

        return null;
    }
}
