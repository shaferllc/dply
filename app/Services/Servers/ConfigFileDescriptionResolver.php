<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * One-line hints for allowlisted config paths in the unified Configuration
 * editor file picker (and webserver config tab). Webserver engine paths
 * delegate to {@see WebserverConfigDocLinks}; everything else is matched here.
 */
final class ConfigFileDescriptionResolver
{
    public function __construct(
        private WebserverConfigDocLinks $webserverDocs,
    ) {}

    public function hintFor(string $path, ?string $engine = null, ?string $group = null): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if ($hint = $this->hintFromCatalogEntry($path)) {
            return $hint;
        }

        if ($hint = $this->hintFromPathRules($path)) {
            return $hint;
        }

        if (is_string($engine) && $engine !== '') {
            $desc = $this->webserverDocs->describe($engine, $path);
            if ($desc !== null) {
                return $this->firstSentence($desc);
            }
        }

        return $this->hintForGroup($path, $group);
    }

    public function roleFor(string $path, ?string $engine = null, ?string $group = null): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if ($role = $this->roleFromCatalogEntry($path)) {
            return $role;
        }

        if ($role = $this->roleFromPathRules($path)) {
            return $role;
        }

        if (is_string($engine) && $engine !== '') {
            $role = $this->webserverDocs->roleFor($engine, $path);
            if ($role !== null) {
                return $role;
            }
        }

        return $this->roleForGroup($path, $group);
    }

    public function roleLabel(?string $role): ?string
    {
        if ($role === null || $role === '') {
            return null;
        }

        return match ($role) {
            'vhost' => __('Vhost'),
            'main' => __('Main config'),
            'snippet' => __('Snippet'),
            'module' => __('Module'),
            'cache' => __('Cache'),
            'metrics' => __('Metrics'),
            'dynamic' => __('Dynamic routing'),
            'ports' => __('Ports'),
            'template' => __('Template'),
            'admin' => __('Admin'),
            'ini' => __('PHP ini'),
            'pool' => __('FPM pool'),
            'server' => __('Server'),
            'fragment' => __('Fragment'),
            'daemon' => __('Daemon'),
            'apt' => __('APT'),
            'program' => __('Program'),
            default => null,
        };
    }

    private function hintFromCatalogEntry(string $path): ?string
    {
        foreach ((array) config('server_manage.config_file_catalog', []) as $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }
            foreach ($groupDef['entries'] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['path'] ?? '') === $path) {
                    $hint = $entry['hint'] ?? $entry['description'] ?? null;

                    return is_string($hint) && $hint !== '' ? $hint : null;
                }
            }
        }

        return null;
    }

    private function roleFromCatalogEntry(string $path): ?string
    {
        foreach ((array) config('server_manage.config_file_catalog', []) as $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }
            foreach ($groupDef['entries'] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['path'] ?? '') === $path) {
                    $role = $entry['role'] ?? null;

                    return is_string($role) && $role !== '' ? $role : null;
                }
            }
        }

        return null;
    }

    private function roleFromPathRules(string $path): ?string
    {
        foreach ((array) config('server_manage.config_file_hints', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $pattern = $rule['pattern'] ?? null;
            $role = $rule['role'] ?? null;
            if (! is_string($pattern) || ! is_string($role) || $role === '') {
                continue;
            }
            if (str_starts_with($pattern, '~')) {
                if (preg_match($pattern, $path) === 1) {
                    return $role;
                }
            } elseif ($pattern === $path) {
                return $role;
            }
        }

        return null;
    }

    private function hintFromPathRules(string $path): ?string
    {
        foreach ((array) config('server_manage.config_file_hints', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $pattern = $rule['pattern'] ?? null;
            $hint = $rule['hint'] ?? null;
            if (! is_string($pattern) || ! is_string($hint) || $hint === '') {
                continue;
            }
            if (str_starts_with($pattern, '~')) {
                if (preg_match($pattern, $path) === 1) {
                    return $hint;
                }
            } elseif ($pattern === $path) {
                return $hint;
            }
        }

        return null;
    }

    private function hintForGroup(string $path, ?string $group): ?string
    {
        return match ($group) {
            'php' => $this->hintForPhpPath($path),
            'redis_db' => $this->hintForRedisDbPath($path),
            'system' => $this->hintForSystemPath($path),
            'supervisor' => $this->hintForSupervisorPath($path),
            default => null,
        };
    }

    private function roleForGroup(string $path, ?string $group): ?string
    {
        return match ($group) {
            'php' => $this->roleForPhpPath($path),
            'redis_db' => $this->roleForRedisDbPath($path),
            'system' => $this->roleForSystemPath($path),
            'supervisor' => $this->roleForSupervisorPath($path),
            default => null,
        };
    }

    private function roleForPhpPath(string $path): ?string
    {
        if (preg_match('#^/etc/php/[\d.]+/(cli|fpm)/php\.ini$#', $path) === 1) {
            return 'ini';
        }
        if (preg_match('#^/etc/php/[\d.]+/fpm/pool\.d/[^/]+\.conf$#', $path) === 1) {
            return 'pool';
        }

        return null;
    }

    private function roleForRedisDbPath(string $path): ?string
    {
        if (in_array($path, ['/etc/redis/redis.conf', '/etc/mysql/my.cnf'], true)) {
            return 'server';
        }
        if (str_starts_with($path, '/etc/mysql/mariadb.conf.d/')) {
            return 'fragment';
        }

        return null;
    }

    private function roleForSystemPath(string $path): ?string
    {
        return match ($path) {
            '/etc/ssh/sshd_config' => 'daemon',
            '/etc/apt/apt.conf.d/50unattended-upgrades', '/etc/apt/apt.conf.d/20auto-upgrades' => 'apt',
            default => null,
        };
    }

    private function roleForSupervisorPath(string $path): ?string
    {
        if ($path === '/etc/supervisor/supervisord.conf') {
            return 'main';
        }
        if (str_starts_with($path, '/etc/supervisor/conf.d/')) {
            return 'program';
        }

        return null;
    }

    private function hintForPhpPath(string $path): ?string
    {
        if (preg_match('#^/etc/php/[\d.]+/cli/php\.ini$#', $path) === 1) {
            return __('PHP settings for CLI — deploy scripts, artisan, and cron.');
        }
        if (preg_match('#^/etc/php/[\d.]+/fpm/php\.ini$#', $path) === 1) {
            return __('PHP settings for FPM — affects every web request.');
        }
        if (preg_match('#^/etc/php/[\d.]+/fpm/pool\.d/([^/]+)\.conf$#', $path, $m) === 1) {
            return __('FPM pool :name — workers, user, and listen socket for that pool.', ['name' => $m[1]]);
        }

        return null;
    }

    private function hintForRedisDbPath(string $path): ?string
    {
        if ($path === '/etc/redis/redis.conf') {
            return __('Redis server — memory, persistence, bind address, and eviction.');
        }
        if ($path === '/etc/mysql/my.cnf') {
            return __('MySQL/MariaDB server defaults and global options.');
        }
        if (str_starts_with($path, '/etc/mysql/mariadb.conf.d/')) {
            return __('MariaDB drop-in fragment — usually charset, InnoDB, or logging tweaks.');
        }

        return null;
    }

    private function hintForSystemPath(string $path): ?string
    {
        return match ($path) {
            '/etc/ssh/sshd_config' => __('SSH daemon — ports, auth methods, and access controls.'),
            '/etc/apt/apt.conf.d/50unattended-upgrades' => __('Which packages auto-update and reboot policy.'),
            '/etc/apt/apt.conf.d/20auto-upgrades' => __('Enables or pauses unattended security upgrades.'),
            default => null,
        };
    }

    private function hintForSupervisorPath(string $path): ?string
    {
        if ($path === '/etc/supervisor/supervisord.conf') {
            return __('Supervisor main config — socket, logging, and include paths.');
        }
        if (str_starts_with($path, '/etc/supervisor/conf.d/')) {
            return __('Supervisor program or group — queue workers and long-running processes.');
        }

        return null;
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $text, $matches) === 1) {
            $sentence = trim($matches[1]);
            if (mb_strlen($sentence) <= 160) {
                return $sentence;
            }
        }

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'…' : $text;
    }
}
