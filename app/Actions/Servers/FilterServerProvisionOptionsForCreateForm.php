<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use Illuminate\Support\Arr;

/**
 * Applies optional per-row rules from config/server_provision_options.php:
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
        'providers',
        'exclude_providers',
        'requires_linked_credential',
        'only_server_roles',
        'exclude_server_roles',
    ];

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
            return $this->stripMetaFromConfig();
        }

        $raw = config('server_provision_options', []);

        $keys = ['server_roles', 'cache_services', 'webservers', 'php_versions', 'databases'];
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
        $keys = ['server_roles', 'cache_services', 'webservers', 'php_versions', 'databases'];
        $out = [];
        foreach ($keys as $key) {
            $rows = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $stripped = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }
                $stripped[] = Arr::except($row, self::STRIP_ROW_KEYS);
            }
            $out[$key] = array_values($stripped);
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
            if (! is_array($row) || ! isset($row['id'])) {
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

        return array_values($filtered);
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
