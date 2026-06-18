<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Modules\Scaffold\Jobs\RunLaravelScaffoldJob;
use App\Modules\Scaffold\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Sites\ChooseApp;
use App\Models\Server;
use App\Support\Servers\DatabaseWorkspaceEngines;

/**
 * Data-driven registry of applications the choose-app flow can install on a
 * site. Each entry is a tile rendered on sites.choose-app; the entry's
 * `kind` tells {@see ChooseApp} how to act on it.
 *
 * Adding a new app is (mostly) a matter of adding an array entry here — no
 * new component code — except for `scaffold` kinds, which need a backing
 * pipeline job. See docs/CHOOSE_APP_FLOW.md.
 *
 * Tile `kind` values:
 *  - scaffold:  real one-click installer backed by a queued pipeline job
 *               (WordPress / Laravel today). Sets meta.scaffold + dispatches
 *               `pipeline_job`; the site shows the scaffold-install flow inside
 *               the workspace shell (Show) while STATUS_SCAFFOLDING.
 *  - import:    bring an existing git repository. Reveals repo URL + branch.
 *  - preset:    import with framework defaults pre-filled (web subdir, build
 *               command hint). Really "import with sensible defaults" — not a
 *               fresh install. Promote to `scaffold` once a real pipeline
 *               exists.
 *  - static:    pure static HTML site (SiteType::Static), no runtime.
 *  - blank:     empty PHP site the user can re-choose later (Blank / Skip).
 */
class AppCatalog
{
    /**
     * Tiles available for the given server. VM hosts only for now;
     * container / serverless keep their existing dedicated create flows, so
     * this returns an empty list for them.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * DB engine families each DB-backed installer supports. Apps absent here (or
     * with needs_db=false) carry no DB constraint. Drives the architecture gate.
     */
    private const SUPPORTED_DB_ENGINES = [
        'wordpress' => ['mysql', 'mariadb'],
        'laravel' => ['mysql', 'mariadb', 'postgres', 'sqlite'],
        'craft' => ['mysql', 'mariadb', 'postgres'],
        'drupal' => ['mysql', 'mariadb', 'postgres', 'sqlite'],
    ];

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forServer(Server $server): array
    {
        if (! $server->isVmHost()) {
            return [];
        }

        $installedFamilies = $this->installedDatabaseFamilies($server);

        return collect($this->vmTiles())
            // Architecture gate: hide a DB-backed installer when the server has
            // no engine it supports (e.g. WordPress on a Postgres-only box).
            ->filter(function (array $tile) use ($installedFamilies): bool {
                if (! ($tile['needs_db'] ?? false)) {
                    return true;
                }
                $supported = self::SUPPORTED_DB_ENGINES[$tile['key']] ?? null;

                return $supported === null
                    || array_intersect($supported, $installedFamilies) !== [];
            })
            // Auto-install (scaffold) apps are temporarily marked "coming soon".
            ->map(function (array $tile): array {
                $tile['coming_soon'] = ($tile['kind'] ?? '') === 'scaffold';

                return $tile;
            })
            ->values()
            ->all();
    }

    /**
     * Normalized families (mysql/mariadb/postgres/sqlite) of every engine
     * installed on the server.
     *
     * @return array<int, array<string, mixed>>
     */
    private function installedDatabaseFamilies(Server $server): array
    {
        return $server->databaseEngines()
            ->get(['engine'])
            ->map(fn ($e) => DatabaseWorkspaceEngines::family((string) $e->engine))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Look up a single tile by key for a server, or null when it is not part
     * of that server's catalog (guards against tampered/stale form input).
     *
     * @return array<string, mixed>|null
     */
    public function tile(Server $server, string $key): ?array
    {
        foreach ($this->forServer($server) as $tile) {
            if (($tile['key'] ?? null) === $key) {
                return $tile;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vmTiles(): array
    {
        return [
            [
                'key' => 'git',
                'label' => __('Git repository'),
                'description' => __('Deploy an existing application from a Git repository.'),
                'icon' => 'heroicon-o-code-bracket',
                'kind' => 'import',
                'needs_db' => false,
                'framework' => '',
                'web_subdir' => '/public',
            ],
            [
                'key' => 'wordpress',
                'label' => __('WordPress'),
                'description' => __('Install a fresh WordPress site, database and admin user included. Managed in place — no Git repo.'),
                'icon' => 'heroicon-o-newspaper',
                'kind' => 'scaffold',
                'needs_db' => true,
                'needs_admin_email' => true,
                'framework' => 'wordpress',
                'web_subdir' => '',
                'pipeline_job' => RunWordPressScaffoldJob::class,
            ],
            [
                'key' => 'laravel',
                'label' => __('Laravel'),
                'description' => __('Scaffold a new Laravel application with a starter kit and database.'),
                'icon' => 'heroicon-o-bolt',
                'kind' => 'scaffold',
                'needs_db' => true,
                'needs_admin_email' => true,
                'framework' => 'laravel',
                'web_subdir' => '/public',
                'pipeline_job' => RunLaravelScaffoldJob::class,
            ],
            [
                'key' => 'statamic',
                'label' => __('Statamic'),
                'description' => __('Install Statamic — the flat-file Laravel CMS. No database required.'),
                'icon' => 'heroicon-o-document-text',
                'kind' => 'scaffold',
                'needs_db' => false,
                'framework' => 'statamic',
                'web_subdir' => '/public',
                'recipe' => [
                    'package' => 'statamic/statamic',
                    'needs_db' => false,
                    'env' => 'laravel',
                    'migrate' => false,
                ],
            ],
            [
                'key' => 'symfony',
                'label' => __('Symfony'),
                'description' => __('Install a fresh Symfony skeleton application.'),
                'icon' => 'heroicon-o-squares-2x2',
                'kind' => 'scaffold',
                'needs_db' => false,
                'framework' => 'symfony',
                'web_subdir' => '/public',
                'recipe' => [
                    'package' => 'symfony/skeleton',
                    'needs_db' => false,
                    'env' => 'none',
                    'migrate' => false,
                ],
            ],
            [
                'key' => 'craft',
                'label' => __('Craft CMS'),
                'description' => __('Install Craft CMS with a database; finish the quick setup in your browser.'),
                'icon' => 'heroicon-o-cube',
                'kind' => 'scaffold',
                'needs_db' => true,
                'framework' => 'craft',
                'web_subdir' => '/web',
                'recipe' => [
                    'package' => 'craftcms/craft',
                    'needs_db' => true,
                    'env' => 'none',
                    'migrate' => false,
                    'finish_in_browser' => true,
                ],
            ],
            [
                'key' => 'drupal',
                'label' => __('Drupal'),
                'description' => __('Install Drupal with a database; finish the install wizard in your browser. Managed in place — no Git repo.'),
                'icon' => 'heroicon-o-globe-alt',
                'kind' => 'scaffold',
                'needs_db' => true,
                'framework' => 'drupal',
                'web_subdir' => '/web',
                'recipe' => [
                    'package' => 'drupal/recommended-project',
                    'needs_db' => true,
                    'env' => 'none',
                    'migrate' => false,
                    'finish_in_browser' => true,
                ],
            ],
            [
                'key' => 'static',
                'label' => __('Static HTML site'),
                'description' => __('Serve a static site straight from a directory — no runtime.'),
                'icon' => 'heroicon-o-document',
                'kind' => 'static',
                'needs_db' => false,
                'framework' => '',
                'web_subdir' => '',
            ],
            [
                'key' => 'blank',
                'label' => __('Blank / Skip'),
                'description' => __('Leave the site empty for now — pick an application whenever you’re ready.'),
                'icon' => 'heroicon-o-minus-circle',
                'kind' => 'blank',
                'needs_db' => false,
                'framework' => '',
                'web_subdir' => '/public',
            ],
        ];
    }
}
