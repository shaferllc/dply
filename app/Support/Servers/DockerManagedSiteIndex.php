<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Maps dply-managed Docker sites on a server to compose project names and
 * container name prefixes for the Docker workspace.
 */
final class DockerManagedSiteIndex
{
    /**
     * @return array{
     *     sites: list<array{id: string, name: string, slug: string, compose_project: string, url: string}>,
     *     project_to_site: array<string, array{id: string, name: string, slug: string, url: string}>,
     * }
     */
    public static function for(Server $server): array
    {
        $sites = [];
        $projectToSite = [];

        $server->loadMissing('sites');

        foreach ($server->sites as $site) {
            $site->setRelation('server', $server);

            if (! self::siteUsesDockerStack($site)) {
                continue;
            }

            $slug = Str::slug($site->slug ?: $site->name ?: 'site', '-');
            $composeProject = basename(rtrim($site->effectiveRepositoryPath(), '/'));
            if ($composeProject === '' || $composeProject === '.') {
                $composeProject = $slug;
            }

            $row = [
                'id' => (string) $site->id,
                'name' => (string) $site->name,
                'slug' => $slug,
                'compose_project' => $composeProject,
                'url' => route('sites.show', ['server' => $server, 'site' => $site]),
            ];

            $sites[] = $row;
            $projectToSite[strtolower($composeProject)] = $row;
            $projectToSite[strtolower($slug)] = $row;
        }

        return [
            'sites' => $sites,
            'project_to_site' => $projectToSite,
        ];
    }

    /**
     * @param  array{name: string, image?: string}  $container
     * @param  array{project_to_site: array<string, array{id: string, name: string, slug: string, url: string}>}  $index
     * @return array{id: string, name: string, slug: string, url: string}|null
     */
    public static function siteForContainer(array $container, array $index): ?array
    {
        $name = strtolower($container['name']);
        if ($name === '') {
            return null;
        }

        foreach ($index['project_to_site'] as $key => $site) {
            if ($key !== '' && str_contains($name, $key)) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @param  array{name: string}  $project
     * @param  array{project_to_site: array<string, array{id: string, name: string, slug: string, url: string}>}  $index
     * @return array{id: string, name: string, slug: string, url: string}|null
     */
    public static function siteForComposeProject(array $project, array $index): ?array
    {
        $name = strtolower($project['name']);

        return $index['project_to_site'][$name] ?? null;
    }

    private static function siteUsesDockerStack(Site $site): bool
    {
        $meta = ($site->meta );

        if (is_array($meta['docker_runtime'] ?? null)
            && (
                filled($meta['docker_runtime']['compose_yaml'] ?? null)
                || filled($meta['docker_runtime']['last_deployed_at'] ?? null)
            )) {
            return true;
        }

        return $site->usesDockerRuntime();
    }
}
