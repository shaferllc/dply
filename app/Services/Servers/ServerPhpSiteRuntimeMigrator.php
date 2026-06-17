<?php

namespace App\Services\Servers;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

class ServerPhpSiteRuntimeMigrator
{
    public function __construct(
        protected ServerPhpManager $phpManager,
    ) {}

    /**
     * @return Collection<int, Site>
     */
    public function sitesUsingVersion(Server $server, string $version): Collection
    {
        $version = $this->phpManager->normalizeVersionId($version) ?? '';

        if ($version === '') {
            return collect();
        }

        return $server->sites()
            ->where('runtime', 'php')
            ->where('runtime_version', $version)
            ->orderBy('name')
            ->get();
    }

    public function countSitesUsingVersion(Server $server, string $version): int
    {
        return $this->sitesUsingVersion($server, $version)->count();
    }

    /**
     * @param  array<string, mixed> $installedIds
     */
    public function resolveMigrationTargetVersion(array $installedIds, string $fromVersion): ?string
    {
        $fromVersion = $this->phpManager->normalizeVersionId($fromVersion) ?? '';
        $installedIds = $this->phpManager->normalizeVersionList($installedIds);

        $candidates = array_values(array_filter(
            $installedIds,
            fn (string $id): bool => $id !== $fromVersion,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => version_compare($b, $a));

        return $candidates[0];
    }

    /**
     * @return array{
     *     migrated_count: int,
     *     target_version: string,
     *     site_names: list<string>
     * }
     */
    public function migrateSitesUsingVersion(
        Server $server,
        string $fromVersion,
        string $toVersion,
        ?string $userId = null,
    ): array {
        $fromVersion = $this->phpManager->normalizeVersionId($fromVersion) ?? '';
        $toVersion = $this->phpManager->normalizeVersionId($toVersion) ?? '';

        if ($fromVersion === '' || $toVersion === '') {
            throw new \RuntimeException('PHP source and target versions are required.');
        }

        if ($fromVersion === $toVersion) {
            throw new \RuntimeException('Choose a different PHP version to move sites to.');
        }

        $installedIds = $this->phpManager->installedVersionIds($server->fresh());
        if (! in_array($toVersion, $installedIds, true)) {
            throw new \RuntimeException('Install PHP '.$toVersion.' before moving sites to it.');
        }

        $sites = $this->sitesUsingVersion($server, $fromVersion);
        $siteNames = [];

        foreach ($sites as $site) {
            $oldVersion = $site->runtime_version;
            $site->runtime = 'php';
            $site->runtime_version = $toVersion;
            $site->save();

            $org = $server->organization;
            $user = $userId !== null ? User::query()->find($userId) : null;
            if ($org !== null && $user !== null) {
                audit_log($org, $user, 'site.php_settings_updated', $site, [
                    'runtime_version' => $oldVersion,
                    'migration' => 'server_php_workspace',
                ], [
                    'runtime_version' => $toVersion,
                    'migration' => 'server_php_workspace',
                ]);
            }

            ApplySiteWebserverConfigJob::dispatch($site->id, $userId);
            $siteNames[] = $site->name;
        }

        return [
            'migrated_count' => count($siteNames),
            'target_version' => $toVersion,
            'site_names' => $siteNames,
        ];
    }
}
