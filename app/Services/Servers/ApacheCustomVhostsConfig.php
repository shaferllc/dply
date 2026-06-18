<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Ad-hoc Apache VirtualHost blocks outside dply's per-site provisioner.
 * Each vhost is a standalone file:
 *   /etc/apache2/sites-available/dply-custom-{slug}.conf
 * symlinked into sites-enabled. Save / add / remove run through
 * {@see RemoteWebserverConfigService} (snapshot → install → apachectl configtest)
 * with auto-revert on validation failure, then reload Apache.
 */
class ApacheCustomVhostsConfig
{
    public const FILE_PREFIX = 'dply-custom-';

    /**
     * @return array{vhosts: list<array{slug: string, path: string, server_name: string, server_aliases: list<string>, document_root: string, php_socket: string}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        $available = rtrim((string) config('sites.apache_sites_available'), '/');

        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec(
                'sudo -n ls -1 '.escapeshellarg($available.'/'.self::FILE_PREFIX).'*.conf 2>/dev/null || true',
                15,
            );
        } catch (\Throwable) {
            return ['vhosts' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        if ($paths === []) {
            return ['vhosts' => [], 'unreadable' => false];
        }

        $vhosts = [];
        foreach ($paths as $path) {
            try {
                $ssh = new SshConnection($server);
                $contents = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
                if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $basename = basename($path, '.conf');
            $slug = str_starts_with($basename, self::FILE_PREFIX)
                ? substr($basename, strlen(self::FILE_PREFIX))
                : $basename;

            $vhosts[] = array_merge(
                ['slug' => $slug, 'path' => $path],
                $this->parseVirtualHost($contents),
            );
        }

        usort($vhosts, fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return ['vhosts' => $vhosts, 'unreadable' => false];
    }

    /**
     * @param  array{server_name: string, server_aliases?: list<string>|string, document_root: string, php_socket?: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        foreach ($this->read($server)['vhosts'] as $vhost) {
            if (($vhost['slug'] ?? '') === $slug) {
                throw new \RuntimeException("A custom vhost `{$slug}` already exists.");
            }
        }

        $contents = $this->render($slug, $fields);
        $this->writeAndEnable($server, $path, $contents, $emitter, 'add custom vhost '.$slug);
    }

    /**
     * @param  array{server_name: string, server_aliases?: list<string>|string, document_root: string, php_socket?: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        $exists = false;
        foreach ($this->read($server)['vhosts'] as $vhost) {
            if (($vhost['slug'] ?? '') === $slug) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            throw new \RuntimeException("No custom vhost `{$slug}` found.");
        }

        $contents = $this->render($slug, $fields);
        $this->writeAndEnable($server, $path, $contents, $emitter, 'save custom vhost '.$slug);
    }

    /**
     * @throws \RuntimeException
     */
    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);
        $enabled = rtrim((string) config('sites.apache_sites_enabled'), '/').'/'.basename($path);

        $ssh = new SshConnection($server);
        $emit->step('apache-custom-vhosts', 'Removing '.$path);
        $ssh->exec(sprintf(
            'sudo -n rm -f %s %s && (sudo -n apachectl configtest 2>&1 && (sudo -n systemctl reload apache2 2>/dev/null || sudo -n service apache2 reload))',
            escapeshellarg($path),
            escapeshellarg($enabled),
        ), 30);

        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to remove custom vhost or reload Apache.');
            throw new \RuntimeException('Failed to remove custom vhost or reload Apache.');
        }

        $emit->success('Custom vhost '.$slug.' removed.');
    }

    /**
     * @param  array{server_name: string, server_aliases?: list<string>|string, document_root: string, php_socket?: string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $serverName = trim((string) ($fields['server_name'] ?? ''));
        $aliases = $this->normalizeList($fields['server_aliases'] ?? []);
        $documentRoot = rtrim(trim((string) ($fields['document_root'] ?? '')), '/');
        $phpSocket = trim((string) ($fields['php_socket'] ?? ''));

        if ($serverName === '') {
            throw new \InvalidArgumentException('ServerName is required.');
        }
        if ($documentRoot === '') {
            throw new \InvalidArgumentException('Document root is required.');
        }

        $logBasename = self::FILE_PREFIX.$slug;

        $lines = [
            '# dply custom vhost: '.$slug,
            '<VirtualHost *:80>',
            '    ServerName '.$serverName,
        ];

        if ($aliases !== []) {
            $lines[] = '    ServerAlias '.implode(' ', $aliases);
        }

        $lines[] = '    DocumentRoot '.$documentRoot;
        $lines[] = '    ErrorLog ${APACHE_LOG_DIR}/'.$logBasename.'-error.log';
        $lines[] = '    CustomLog ${APACHE_LOG_DIR}/'.$logBasename.'-access.log combined';
        $lines[] = '';
        $lines[] = '    <Directory '.$documentRoot.'>';
        $lines[] = '        AllowOverride All';
        $lines[] = '        Require all granted';
        $lines[] = '        Options FollowSymLinks';
        $lines[] = '        DirectoryIndex index.html index.php';

        if ($phpSocket !== '') {
            $lines[] = '        FallbackResource /index.php';
        }

        $lines[] = '    </Directory>';

        if ($phpSocket !== '') {
            $handler = str_contains($phpSocket, '|fcgi://')
                ? $phpSocket
                : 'proxy:unix:'.$phpSocket.'|fcgi://localhost/';
            $lines[] = '';
            $lines[] = '    <FilesMatch \.php$>';
            $lines[] = '        SetHandler "'.str_replace('"', '\\"', $handler).'"';
            $lines[] = '    </FilesMatch>';
        }

        $lines[] = '</VirtualHost>';

        return implode("\n", $lines)."\n";
    }

    private function writeAndEnable(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter, string $reason): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $enabled = rtrim((string) config('sites.apache_sites_enabled'), '/').'/'.basename($path);

        $emit->step('apache-custom-vhosts', 'Writing '.$path.' ('.$reason.')');
        $result = app(RemoteWebserverConfigService::class)->write($server, 'apache', $path, $contents, $emit);

        if (! ($result['validate_ok'])) {
            throw new \RuntimeException(trim((string) ($result['validate_output'] ?? 'apachectl configtest rejected the new file.')));
        }

        $ssh = new SshConnection($server);
        $emit->step('apache-custom-vhosts', 'Enabling '.$enabled);
        $ssh->exec(sprintf(
            'sudo -n ln -sf %s %s && (sudo -n systemctl reload apache2 2>/dev/null || sudo -n service apache2 reload)',
            escapeshellarg($path),
            escapeshellarg($enabled),
        ), 20);

        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('File saved but Apache reload failed.');
        }

        $emit->success('Custom vhost saved and Apache reloaded.');
    }

    private function pathForSlug(string $slug): string
    {
        return rtrim((string) config('sites.apache_sites_available'), '/').'/'.self::FILE_PREFIX.$slug.'.conf';
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new \InvalidArgumentException('Slug is required and may only contain lowercase letters, digits, and hyphens.');
        }

        return $slug;
    }

    /**
     * @return list<string>
     * @param  array<string, mixed> $value
     */
    private function normalizeList(array|string $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        return array_values(array_filter(array_map('trim', $value), fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array{server_name: string, server_aliases: list<string>, document_root: string, php_socket: string}
     */
    private function parseVirtualHost(string $contents): array
    {
        $serverName = '';
        $aliases = [];
        $documentRoot = '';
        $phpSocket = '';

        if (preg_match('/^\s*ServerName\s+(\S+)/m', $contents, $m) === 1) {
            $serverName = trim($m[1]);
        }

        if (preg_match('/^\s*ServerAlias\s+([^;\n]+)/m', $contents, $m) === 1) {
            $aliases = $this->normalizeList(trim($m[1]));
        }

        if (preg_match('/^\s*DocumentRoot\s+(\S+)/m', $contents, $m) === 1) {
            $documentRoot = trim($m[1]);
        }

        if (preg_match('/SetHandler\s+"([^"]+)"/', $contents, $m) === 1) {
            $handler = trim($m[1]);
            if (str_starts_with($handler, 'proxy:unix:')) {
                $phpSocket = preg_replace('/\|fcgi:\/\/localhost\/$/', '', substr($handler, strlen('proxy:unix:'))) ?: $handler;
            } else {
                $phpSocket = $handler;
            }
        }

        return [
            'server_name' => $serverName,
            'server_aliases' => $aliases,
            'document_root' => $documentRoot,
            'php_socket' => $phpSocket,
        ];
    }
}
