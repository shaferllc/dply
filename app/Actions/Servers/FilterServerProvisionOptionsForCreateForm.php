<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Support\Servers\CacheEngineAvailability;
use App\Support\Servers\DatabaseEngineAvailability;
use Illuminate\Support\Arr;

/**
 * Applies optional per-row rules from config/server_provision_options.php:
 * - enabled: `false` withdraws the row from every picker (temporary rollbacks)
 * - providers: whitelist of form.type values (e.g. digitalocean, aws)
 * - exclude_providers: blacklist of form.type values
 * - requires_linked_credential: row is omitted until the org has a credential for this provider
 * - only_server_roles / exclude_server_roles: filter rows by the selected server_role
 *
 * Internal keys are stripped from the returned arrays for the UI.
 */
final class FilterServerProvisionOptionsForCreateForm
{
    use AsObject;

    /** @var list<string> */
    private const STRIP_ROW_KEYS = [
        'enabled',
        'providers',
        'exclude_providers',
        'requires_linked_credential',
        'only_server_roles',
        'exclude_server_roles',
    ];

    /**
     * Install profiles that should appear in the picker.
     *
     * Withdrawn profiles stay in config because their ids are internal
     * identity — role → profile mapping, the dedicated database/cache create
     * flows, and `Server::meta.install_profile` all resolve against the raw
     * list. Only the picker filters.
     *
     * @return list<array<string, mixed>>
     */
    public static function offeredInstallProfiles(): array
    {
        $rows = (array) config('server_provision_options.install_profiles', []);

        return array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row) && ($row['enabled'] ?? true) !== false,
        ));
    }

    /**
     * A row is offered unless it carries an explicit `enabled => false`. Kept
     * separate from the provider/role rules because it's a blanket withdrawal:
     * it applies on the custom-provider path too, which skips filterRows().
     *
     * @param  array<string, mixed>  $row
     */
    private function rowIsEnabled(array $row): bool
    {
        return ($row['enabled'] ?? true) !== false;
    }

    /**
     * @return array{
     *     server_roles: list<array<string, mixed>>,
     *     cache_services: list<array<string, mixed>>,
     *     webservers: list<array<string, mixed>>,
     *     php_versions: list<array<string, mixed>>,
     *     databases: list<array<string, mixed>>,
     * }
     */
    public function handle(string $formType, bool $hasLinkedCredentialForProvider, string $serverRole = 'application'): array
    {
        if ($formType === 'custom') {
            return $this->removeComingSoonEngines($this->stripMetaFromConfig());
        }

        $raw = config('server_provision_options', []);

        $keys = ['server_roles', 'cache_services', 'webservers', 'php_versions', 'ruby_versions', 'node_versions', 'python_versions', 'go_versions', 'databases'];
        $out = [];
        foreach ($keys as $key) {
            $rows = $raw[$key] ?? [];
            $out[$key] = $this->filterRows(
                is_array($rows) ? $rows : [],
                $formType,
                $hasLinkedCredentialForProvider,
                $key === 'server_roles' ? null : $serverRole,
            );
        }

        return $this->removeComingSoonEngines($out);
    }

    /**
     * Drop cache + database engines that are still "coming soon" (gated behind
     * cache.* / database.* Pennant flags) from the create wizard. Redis,
     * Valkey, MySQL, PostgreSQL, and SQLite are never gated. Also drops any
     * dedicated cache server role whose engine is gated, so an engine can't be
     * provisioned out from under its own flag.
     *
     * @param  array<string, list<array<string, mixed>>>  $out
     * @return array<string, list<array<string, mixed>>>
     */
    private function removeComingSoonEngines(array $out): array
    {
        if (isset($out['cache_services'])) {
            $out['cache_services'] = array_values(array_filter(
                $out['cache_services'],
                fn (array $row): bool => CacheEngineAvailability::isAvailable((string) ($row['id'] ?? '')),
            ));
        }

        if (isset($out['server_roles'])) {
            $out['server_roles'] = array_values(array_filter(
                $out['server_roles'],
                fn (array $row): bool => CacheEngineAvailability::isAvailable((string) ($row['id'] ?? '')),
            ));
        }

        if (isset($out['databases'])) {
            $out['databases'] = array_values(array_filter(
                $out['databases'],
                fn (array $row): bool => ! DatabaseEngineAvailability::isProvisionOptionComingSoon((string) ($row['id'] ?? '')),
            ));
        }

        return $out;
    }

    /**
     * @return array{
     *     server_roles: list<array<string, mixed>>,
     *     cache_services: list<array<string, mixed>>,
     *     webservers: list<array<string, mixed>>,
     *     php_versions: list<array<string, mixed>>,
     *     databases: list<array<string, mixed>>,
     * }
     */
    private function stripMetaFromConfig(): array
    {
        $raw = config('server_provision_options', []);
        $keys = ['server_roles', 'cache_services', 'webservers', 'php_versions', 'ruby_versions', 'node_versions', 'python_versions', 'go_versions', 'databases'];
        $out = [];
        foreach ($keys as $key) {
            $rows = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $stripped = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }
                if (! $this->rowIsEnabled($row)) {
                    continue;
                }
                $stripped[] = Arr::except($row, self::STRIP_ROW_KEYS);
            }
            $out[$key] = $stripped;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterRows(
        array $rows,
        string $formType,
        bool $hasLinkedCredentialForProvider,
        ?string $serverRole,
    ): array {
        $filtered = [];
        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }
            if (! $this->rowIsEnabled($row)) {
                continue;
            }
            if (($row['requires_linked_credential'] ?? false) === true && ! $hasLinkedCredentialForProvider) {
                continue;
            }
            $providers = $row['providers'] ?? null;
            if (is_array($providers) && $providers !== [] && ! in_array($formType, $providers, true)) {
                continue;
            }
            $exclude = $row['exclude_providers'] ?? null;
            if (is_array($exclude) && in_array($formType, $exclude, true)) {
                continue;
            }
            if ($serverRole !== null && $serverRole !== '' && ! $this->rowMatchesServerRole($row, $serverRole)) {
                continue;
            }
            $filtered[] = Arr::except($row, self::STRIP_ROW_KEYS);
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesServerRole(array $row, string $serverRole): bool
    {
        $only = $row['only_server_roles'] ?? null;
        if (is_array($only) && $only !== [] && ! in_array($serverRole, $only, true)) {
            return false;
        }
        $excluded = $row['exclude_server_roles'] ?? null;
        if (is_array($excluded) && in_array($serverRole, $excluded, true)) {
            return false;
        }

        return true;
    }
}
