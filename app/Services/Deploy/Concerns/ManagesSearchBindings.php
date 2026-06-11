<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\SearchCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `search` binding type — a Laravel Scout search driver. Algolia is
 * BYO keys; Meilisearch/Typesense attach an existing endpoint (host + key) the
 * operator runs (on this server's loopback/private net, or hosted). It injects
 * SCOUT_DRIVER plus the driver's connection env at deploy.
 *
 * On-server provisioning of Meilisearch/Typesense (the Redis-style install flow)
 * is a separate follow-up; v1 attaches an endpoint the operator supplies.
 */
trait ManagesSearchBindings
{
    /** Supported search drivers. */
    public const SEARCH_PROVIDERS = ['algolia', 'meilisearch', 'typesense'];

    /**
     * Composer packages each driver needs beyond laravel/scout, surfaced as a
     * modal note (the app must already require them — deploy runs the app's own
     * composer install).
     *
     * @var array<string, string>
     */
    public const SEARCH_PACKAGES = [
        'algolia' => 'algolia/scout-extended',
        'meilisearch' => 'meilisearch/meilisearch-php',
        'typesense' => 'typesense/typesense-php',
    ];

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachSearch(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::SEARCH_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported search provider.'));
        }

        $creds = $this->resolveSearchCredentials($site, $provider, $params);
        $this->validateSearchCredentials($provider, $creds);

        $binding = $this->persist($site, 'search', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->searchLabel($provider),
            'target_type' => 'search',
            'target_id' => null,
            'injected_env' => $this->searchEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ]);

        $this->maybeSaveSearchCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveSearchCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = SearchCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof SearchCredential) {
                throw new InvalidArgumentException(__('That saved search credential is no longer available.'));
            }

            return is_array($cred->credentials) ? $cred->credentials : [];
        }

        return match ($provider) {
            'algolia' => array_filter([
                'app_id' => trim((string) ($params['app_id'] ?? '')),
                'secret' => trim((string) ($params['secret'] ?? '')),
            ], fn ($v) => $v !== ''),
            'meilisearch' => array_filter([
                'host' => trim((string) ($params['host'] ?? '')),
                'key' => trim((string) ($params['key'] ?? '')),
            ], fn ($v) => $v !== ''),
            'typesense' => array_filter([
                'host' => trim((string) ($params['host'] ?? '')),
                'port' => trim((string) ($params['port'] ?? '')),
                'protocol' => trim((string) ($params['protocol'] ?? '')),
                'api_key' => trim((string) ($params['api_key'] ?? '')),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /** @param array<string, string> $creds */
    private function validateSearchCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'algolia' => ($creds['app_id'] ?? '') === '' || ($creds['secret'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Algolia application ID and admin API key are required.'))
                : null,
            'meilisearch' => ($creds['host'] ?? '') === ''
                ? throw new InvalidArgumentException(__('A Meilisearch host is required.'))
                : null,
            'typesense' => ($creds['host'] ?? '') === '' || ($creds['api_key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('A Typesense host and API key are required.'))
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $creds
     * @return array<string, string>
     */
    private function searchEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'algolia' => [
                'SCOUT_DRIVER' => 'algolia',
                'ALGOLIA_APP_ID' => (string) ($creds['app_id'] ?? ''),
                'ALGOLIA_SECRET' => (string) ($creds['secret'] ?? ''),
            ],
            'meilisearch' => array_filter([
                'SCOUT_DRIVER' => 'meilisearch',
                'MEILISEARCH_HOST' => (string) ($creds['host'] ?? ''),
                'MEILISEARCH_KEY' => (string) ($creds['key'] ?? ''),
            ], fn ($v) => $v !== ''),
            'typesense' => array_filter([
                'SCOUT_DRIVER' => 'typesense',
                'TYPESENSE_API_KEY' => (string) ($creds['api_key'] ?? ''),
                'TYPESENSE_HOST' => (string) ($creds['host'] ?? ''),
                'TYPESENSE_PORT' => (string) ($creds['port'] ?? '') ?: '8108',
                'TYPESENSE_PROTOCOL' => (string) ($creds['protocol'] ?? '') ?: 'http',
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    private function searchLabel(string $provider): string
    {
        return match ($provider) {
            'algolia' => 'Algolia',
            'meilisearch' => 'Meilisearch',
            'typesense' => 'Typesense',
            default => ucfirst($provider),
        };
    }

    /**
     * Every env key the search binding can own across drivers, so switching
     * drivers clears the previous driver's connection vars.
     *
     * @return list<string>
     */
    private function searchOwnedEnvKeys(): array
    {
        return [
            'SCOUT_DRIVER',
            'ALGOLIA_APP_ID', 'ALGOLIA_SECRET',
            'MEILISEARCH_HOST', 'MEILISEARCH_KEY',
            'TYPESENSE_API_KEY', 'TYPESENSE_HOST', 'TYPESENSE_PORT', 'TYPESENSE_PROTOCOL',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $creds
     */
    private function maybeSaveSearchCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $name = $this->searchLabel($provider).' '.__('search');
        }

        SearchCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
