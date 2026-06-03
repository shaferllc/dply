<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Jobs\RunSiteFixerJob;
use App\Jobs\TestSiteHealthJob;

/**
 * Registry of one-click "smart fixers" for the things that commonly break a
 * deployed app: missing migrations, missing PHP DB drivers / client binaries,
 * an un-built front-end, missing Composer deps, a stale config cache, broken
 * storage permissions, … Each entry knows how to RECOGNISE its failure (a log
 * signature) and how to FIX it (an artisan or shell command run on the server).
 *
 * {@see TestSiteHealthJob} matches signatures to surface the right
 * buttons; {@see RunSiteFixerJob} runs the chosen fix over SSH. The
 * UI only ever passes a key — never a raw command — so there's no injection
 * surface, and the command set is auditable in one place.
 */
final class SiteFixers
{
    /**
     * key => [
     *   label, reason,                 // UI copy
     *   kind: 'artisan'|'shell',       // how it runs
     *   command,                       // artisan subcommand, or a shell line
     *   sudo: bool, cwd: bool,         // run as root? run in the deploy dir?
     *   timeout: int,
     *   detect: ?string                // regex over the app/command output
     * ]
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ---- schema / database ----------------------------------------
            'migrate' => [
                'label' => 'Run database migrations',
                'reason' => 'A database table is missing — migrations have not run on this database.',
                'kind' => 'artisan', 'command' => 'migrate --force', 'sudo' => false, 'cwd' => true, 'timeout' => 300,
                'detect' => '/SQLSTATE\[42P01\]|SQLSTATE\[42S02\]|Undefined table|relation "[^"]+" does not exist|Base table or view not found|no such table/i',
            ],

            // ---- missing PHP DB drivers (could not find driver) -----------
            'install_pgsql_driver' => [
                'label' => 'Install the Postgres PHP driver',
                'reason' => 'PHP is missing pdo_pgsql — a Postgres app fails with "could not find driver".',
                'kind' => 'shell',
                'command' => 'V=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"); apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y "php$V-pgsql" && systemctl restart "php$V-fpm"',
                'sudo' => true, 'cwd' => false, 'timeout' => 300,
                'detect' => '/could not find driver[^)]*pgsql|pgsql[^)]*could not find driver/is',
            ],
            'install_mysql_driver' => [
                'label' => 'Install the MySQL PHP driver',
                'reason' => 'PHP is missing pdo_mysql — a MySQL app fails with "could not find driver".',
                'kind' => 'shell',
                'command' => 'V=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"); apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y "php$V-mysql" && systemctl restart "php$V-fpm"',
                'sudo' => true, 'cwd' => false, 'timeout' => 300,
                'detect' => '/could not find driver[^)]*mysql|mysql[^)]*could not find driver/is',
            ],

            // ---- missing DB client binaries (used by migrate schema load) -
            'install_pg_client' => [
                'label' => 'Install the Postgres client (psql)',
                'reason' => 'psql is not installed — migrate uses it to load the schema dump.',
                'kind' => 'shell',
                'command' => 'apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql-client',
                'sudo' => true, 'cwd' => false, 'timeout' => 300,
                'detect' => '/psql: (not found|command not found)/i',
            ],
            'install_mysql_client' => [
                'label' => 'Install the MySQL client',
                'reason' => 'The mysql client is not installed.',
                'kind' => 'shell',
                'command' => 'apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y default-mysql-client',
                'sudo' => true, 'cwd' => false, 'timeout' => 300,
                'detect' => '/(mysql|mysqldump): (not found|command not found)/i',
            ],

            // ---- front-end / Node -----------------------------------------
            'install_node' => [
                'label' => 'Install Node.js & npm',
                'reason' => 'npm/node is not installed — needed to build front-end assets.',
                'kind' => 'shell',
                'command' => 'apt-get update -y >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs npm',
                'sudo' => true, 'cwd' => false, 'timeout' => 420,
                'detect' => '/\b(npm|node): (not found|command not found)|sh: \d+: (npm|node): not found/i',
            ],
            'build_assets' => [
                'label' => 'Build front-end assets',
                'reason' => 'The Vite manifest is missing — front-end assets were never built.',
                'kind' => 'shell', 'command' => 'npm ci && npm run build', 'sudo' => false, 'cwd' => true, 'timeout' => 600,
                'detect' => '/Vite manifest not found|Unable to locate file in Vite manifest/i',
            ],

            // ---- PHP dependencies -----------------------------------------
            'composer_install' => [
                'label' => 'Install Composer dependencies',
                'reason' => 'A class or vendor/autoload is missing — dependencies are not installed.',
                'kind' => 'shell', 'command' => 'composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader', 'sudo' => false, 'cwd' => true, 'timeout' => 600,
                'detect' => '/(Failed opening required|failed to open stream).{0,40}vendor\/autoload|vendor\/autoload.{0,40}(No such file|failed to open stream)|Please run [\'"]?composer install|Class [\'"][^\'"]+[\'"] not found/i',
            ],

            // ---- app/runtime state ----------------------------------------
            'key_generate' => [
                'label' => 'Generate APP_KEY',
                'reason' => 'No application encryption key is set.',
                'kind' => 'artisan', 'command' => 'key:generate --force', 'sudo' => false, 'cwd' => true, 'timeout' => 60,
                'detect' => '/No application encryption key has been specified|Unsupported cipher or incorrect key length/i',
            ],
            'config_clear' => [
                'label' => 'Clear config cache',
                'reason' => 'Config looks cached/stale.',
                'kind' => 'artisan', 'command' => 'config:clear', 'sudo' => false, 'cwd' => true, 'timeout' => 60,
                'detect' => '/cached config|configuration is cached/i',
            ],
            'optimize_clear' => [
                'label' => 'Clear all caches',
                'reason' => 'Clear cached config, routes, views and events.',
                'kind' => 'artisan', 'command' => 'optimize:clear', 'sudo' => false, 'cwd' => true, 'timeout' => 60,
                'detect' => null,
            ],
            'storage_link' => [
                'label' => 'Re-create the storage symlink',
                'reason' => 'The public/storage symlink is missing.',
                'kind' => 'artisan', 'command' => 'storage:link', 'sudo' => false, 'cwd' => true, 'timeout' => 60,
                'detect' => '/public\/storage.*(No such file|not.*found)|symbolic link.*storage/i',
            ],
            'fix_permissions' => [
                'label' => 'Fix storage permissions',
                'reason' => 'A permission error in storage or bootstrap/cache.',
                'kind' => 'shell', 'command' => 'chmod -R ug+rwX storage bootstrap/cache 2>/dev/null; true', 'sudo' => true, 'cwd' => true, 'timeout' => 120,
                'detect' => '/Permission denied.*(storage|bootstrap\/cache)|failed to open stream: Permission denied|could not be opened.*Permission denied|The (stream|file) .* could not be opened in append mode/i',
            ],
            'queue_restart' => [
                'label' => 'Restart queue workers',
                'reason' => 'Queue workers may be running stale code after a deploy.',
                'kind' => 'artisan', 'command' => 'queue:restart', 'sudo' => false, 'cwd' => true, 'timeout' => 60,
                'detect' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function spec(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Fixers whose signature matches the given error output, in registry order.
     *
     * @return list<array{key: string, label: string, reason: string}>
     */
    public static function detect(string $log): array
    {
        $hits = [];
        foreach (self::all() as $key => $spec) {
            $re = $spec['detect'] ?? null;
            if ($re !== null && preg_match($re, $log) === 1) {
                $hits[] = ['key' => $key, 'label' => $spec['label'], 'reason' => $spec['reason']];
            }
        }

        return $hits;
    }
}
