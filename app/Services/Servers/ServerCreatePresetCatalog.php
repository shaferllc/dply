<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * v1 preset catalog for the server-create wizard's Step 3 ("What it runs").
 *
 * Per the strategy memo: "preset-first. v1 preset list (8): Laravel app,
 * Rails app, Next.js / Node API, Django / FastAPI, Polyglot host, Static
 * host, Database node, Custom. Preset row at the top pre-fills runtimes +
 * role + db + cache + web; users can override anything below."
 *
 * "Polyglot host (PHP+Node+Python+Ruby+Go all installed) is the
 * marketing-pixel-level differentiator and must stay prominent."
 *
 * Each preset is a structured map of the wizard fields it pre-fills:
 *
 *   id          — slug (laravel / rails / nextjs / django / polyglot /
 *                 static / database / custom)
 *   name        — short label shown in the wizard tile
 *   description — one-line tagline
 *   role        — server role (application / database / static / plain)
 *   webserver   — nginx / caddy / openlitespeed / null for non-http
 *   runtimes    — runtime → version pin map written to
 *                 server.meta.runtime_defaults (see
 *                 ServerProvisionCommandBuilder::serverRuntimeDefaults)
 *   php_version — explicit PHP pin (PHP uses ondrej/php apt, not mise)
 *   database    — single engine (mysql84 / postgres17 / null)
 *   cache       — single engine (redis / valkey / null)
 *   featured    — boolean — true tiles bubble to the top of the picker
 *
 * Custom is intentionally last and empty — the wizard's "I'll pick
 * everything myself" escape hatch.
 */
final class ServerCreatePresetCatalog
{
    public const ID_LARAVEL = 'laravel';

    public const ID_RAILS = 'rails';

    public const ID_NEXTJS = 'nextjs';

    public const ID_DJANGO = 'django';

    public const ID_POLYGLOT = 'polyglot';

    public const ID_STATIC = 'static';

    public const ID_DATABASE = 'database';

    public const ID_WORDPRESS = 'wordpress';

    public const ID_CUSTOM = 'custom';

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     role: string,
     *     webserver: ?string,
     *     runtimes: array<string, string>,
     *     php_version: ?string,
     *     database: ?string,
     *     cache: ?string,
     *     featured: bool,
     * }>
     */
    public function all(): array
    {
        return [
            [
                'id' => self::ID_LARAVEL,
                'name' => 'Laravel app',
                'description' => 'PHP-FPM + Nginx + MySQL 8.4 + Redis. Ready for php artisan migrate.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => [],
                'php_version' => '8.4',
                'database' => 'mysql84',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_RAILS,
                'name' => 'Rails app',
                'description' => 'Ruby 3.3 + Nginx → Puma + Postgres 17 + Redis. Ready for bundle exec rails db:migrate.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => ['ruby' => '3.3'],
                'php_version' => null,
                'database' => 'postgres17',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_NEXTJS,
                'name' => 'Next.js / Node API',
                'description' => 'Node 22 + Nginx → reverse proxy + Postgres 17 + Redis.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => ['node' => '22'],
                'php_version' => null,
                'database' => 'postgres17',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_DJANGO,
                'name' => 'Django / FastAPI',
                'description' => 'Python 3.12 + Nginx → gunicorn / uvicorn + Postgres 17 + Redis.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => ['python' => '3.12'],
                'php_version' => null,
                'database' => 'postgres17',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_POLYGLOT,
                'name' => 'Polyglot host',
                'description' => 'PHP 8.4 + Node 22 + Python 3.12 + Ruby 3.3 + Go 1.22, all preinstalled. Postgres 17 + MySQL 8.4 + Redis.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => [
                    'node' => '22',
                    'python' => '3.12',
                    'ruby' => '3.3',
                    'go' => '1.22',
                ],
                'php_version' => '8.4',
                'database' => 'postgres17',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_WORDPRESS,
                'name' => 'WordPress host',
                'description' => 'PHP 8.4 + Nginx + MariaDB 11.4 + Redis + wp-cli preinstalled. Optimised for one-click WordPress scaffolding.',
                'role' => 'application',
                'webserver' => 'nginx',
                'runtimes' => [],
                'php_version' => '8.4',
                'database' => 'mariadb114',
                'cache' => 'redis',
                'featured' => true,
            ],
            [
                'id' => self::ID_STATIC,
                'name' => 'Static host',
                'description' => 'Nginx-only — for built static sites or SPA bundles. No PHP, no DB, no cache.',
                'role' => 'static',
                'webserver' => 'nginx',
                'runtimes' => [],
                'php_version' => null,
                'database' => null,
                'cache' => null,
                'featured' => false,
            ],
            [
                'id' => self::ID_DATABASE,
                'name' => 'Database node',
                'description' => 'Postgres 17 + Redis, no application stack. Use with a separate app server.',
                'role' => 'database',
                'webserver' => null,
                'runtimes' => [],
                'php_version' => null,
                'database' => 'postgres17',
                'cache' => 'redis',
                'featured' => false,
            ],
            [
                'id' => self::ID_CUSTOM,
                'name' => 'Custom',
                'description' => 'Pick everything yourself — start from a clean slate.',
                'role' => 'plain',
                'webserver' => null,
                'runtimes' => [],
                'php_version' => null,
                'database' => null,
                'cache' => null,
                'featured' => false,
            ],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     role: string,
     *     webserver: ?string,
     *     runtimes: array<string, string>,
     *     php_version: ?string,
     *     database: ?string,
     *     cache: ?string,
     *     featured: bool,
     * }|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $preset) {
            if ($preset['id'] === $id) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Translate a preset into the `meta` shape the existing wizard / Server
     * model expects, so the caller can spread it into the meta array.
     *
     * @return array<string, mixed>
     */
    public function toServerMeta(string $id): array
    {
        $preset = $this->find($id);
        if ($preset === null) {
            return [];
        }

        $meta = [
            'preset' => $preset['id'],
            'server_role' => $preset['role'],
        ];

        if ($preset['webserver'] !== null) {
            $meta['webserver'] = $preset['webserver'];
        }
        if ($preset['php_version'] !== null) {
            $meta['php_version'] = $preset['php_version'];
        }
        if ($preset['database'] !== null) {
            $meta['database'] = $preset['database'];
        }
        if ($preset['cache'] !== null) {
            $meta['cache_service'] = $preset['cache'];
        }
        if ($preset['runtimes'] !== []) {
            $meta['runtime_defaults'] = $preset['runtimes'];
        }

        return $meta;
    }
}
