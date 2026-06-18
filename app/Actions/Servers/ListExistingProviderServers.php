<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Support\ServerProviderTypeMap;
use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Existing VM servers for an org, grouped by provider — used on server create
 * to show where infrastructure is already installed.
 */
final class ListExistingProviderServers
{
    use AsObject;

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     provider: string,
     *     region: string,
     *     role_id: string,
     *     role_label: string,
     *     status: string,
     *     sites_count: int
     * }>
     */
    public function handle(?Organization $org, ?string $providerType = null): array
    {
        if (! $org) {
            return [];
        }

        return $this->query($org, $providerType)
            ->map(fn (Server $server): array => $this->mapServer($server))
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<array{id: string, label: string, count: int}>>
     */
    public function rolesByProvider(?Organization $org): array
    {
        return $this->aggregateByProvider($org, function (array &$bucket, Server $server): void {
            $meta = $server->meta;
            $roleId = (string) ($meta['server_role'] ?? 'application');
            $bucket[$roleId] = ($bucket[$roleId] ?? 0) + 1;
        }, fn (array $roles): array => $this->formatRoleRows($roles));
    }

    /**
     * @return array<string, list<array{region: string, label: string, count: int}>>
     */
    public function locationsByProvider(?Organization $org): array
    {
        return $this->aggregateByProvider($org, function (array &$bucket, Server $server): void {
            $region = trim((string) ($server->region ?? ''));
            if ($region === '') {
                $region = 'unknown';
            }
            $bucket[$region] = ($bucket[$region] ?? 0) + 1;
        }, fn (array $regions): array => $this->formatLocationRows($regions));
    }

    /**
     * @return array<string, int>
     */
    public function regionCounts(?Organization $org, string $providerType): array
    {
        if (! $org) {
            return [];
        }

        $counts = [];
        foreach ($this->query($org, $providerType) as $server) {
            $region = trim((string) ($server->region ?? ''));
            if ($region === '') {
                continue;
            }
            $counts[$region] = ($counts[$region] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  callable(array<string, int>, Server): void  $accumulate
     * @param  callable(array<string, int>): list<array<string, mixed>>  $format
     * @return array<string, list<array<string, mixed>>>
     */
    private function aggregateByProvider(?Organization $org, callable $accumulate, callable $format): array
    {
        if (! $org) {
            return [];
        }

        $byProvider = [];
        foreach ($this->query($org) as $server) {
            $providerKey = $this->normalizeProviderKey($server->provider);
            if (! isset($byProvider[$providerKey])) {
                $byProvider[$providerKey] = [];
            }
            $accumulate($byProvider[$providerKey], $server);
        }

        $formatted = [];
        foreach ($byProvider as $providerKey => $bucket) {
            $formatted[$providerKey] = $format($bucket);
        }

        return $formatted;
    }

    /**
     * @return Collection<int, Server>
     */
    private function query(Organization $org, ?string $providerType = null): Collection
    {
        $query = Server::query()
            ->where('organization_id', $org->id)
            ->whereNotNull('provider')
            ->withCount('sites')
            ->orderBy('name');

        if ($providerType !== null && $providerType !== '' && $providerType !== 'custom') {
            $credentialProvider = ServerProviderTypeMap::toCredentialProvider($providerType) ?? $providerType;
            $query->where('provider', $credentialProvider);
        }

        return $query->get([
            'id',
            'name',
            'provider',
            'region',
            'status',
            'meta',
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     provider: string,
     *     region: string,
     *     role_id: string,
     *     role_label: string,
     *     status: string,
     *     sites_count: int
     * }
     */
    private function mapServer(Server $server): array
    {
        $meta = $server->meta;
        $roleId = (string) ($meta['server_role'] ?? 'application');

        return [
            'id' => (string) $server->id,
            'name' => (string) $server->name,
            'provider' => $this->normalizeProviderKey($server->provider),
            'region' => trim((string) ($server->region ?? '')),
            'role_id' => $roleId,
            'role_label' => $this->roleLabel($roleId),
            'status' => (string) $server->status,
            'sites_count' => (int) ($server->sites_count ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $roles
     * @return list<array{id: string, label: string, count: int}>
     */
    private function formatRoleRows(array $roles): array
    {
        $rows = [];
        foreach ($roles as $roleId => $count) {
            $rows[] = [
                'id' => $roleId,
                'label' => $this->roleLabel((string) $roleId),
                'count' => (int) $count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * @param  array<string, int>  $regions
     * @return list<array{region: string, label: string, count: int}>
     */
    private function formatLocationRows(array $regions): array
    {
        $rows = [];
        foreach ($regions as $region => $count) {
            $rows[] = [
                'region' => (string) $region,
                'label' => $region === 'unknown' ? __('Unknown region') : strtoupper((string) $region),
                'count' => (int) $count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $rows;
    }

    private function roleLabel(string $roleId): string
    {
        static $labels = null;
        if ($labels === null) {
            /** @var list<array{id: string, label?: string}> $serverRoles */
            $serverRoles = config('server_provision_options.server_roles', []);
            $labels = collect($serverRoles)
                ->keyBy('id')
                ->map(fn (array $role): string => (string) ($role['label'] ?? $role['id']))
                ->all();
        }

        return (string) ($labels[$roleId] ?? ucfirst(str_replace('_', ' ', $roleId)));
    }

    private function normalizeProviderKey(mixed $provider): string
    {
        if ($provider instanceof ServerProvider) {
            return $provider->value;
        }

        return (string) $provider;
    }
}
