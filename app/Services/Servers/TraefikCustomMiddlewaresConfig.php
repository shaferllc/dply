<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * Standalone HTTP middlewares in /etc/traefik/dynamic/dply-custom-mw-{slug}.yml.
 */
class TraefikCustomMiddlewaresConfig
{
    public const FILE_PREFIX = 'dply-custom-mw-';

    private const DYNAMIC_DIR = '/etc/traefik/dynamic';

    public const TYPES = ['stripPrefix', 'redirectScheme', 'headers', 'basicAuth'];

    /**
     * @return array{middlewares: list<array{slug: string, path: string, type: string, config_summary: string}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec(
                'sudo -n ls -1 '.escapeshellarg(self::DYNAMIC_DIR.'/'.self::FILE_PREFIX).'*.yml 2>/dev/null || true',
                15,
            );
        } catch (\Throwable) {
            return ['middlewares' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        $rows = [];
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

            $basename = basename($path, '.yml');
            $slug = str_starts_with($basename, self::FILE_PREFIX)
                ? substr($basename, strlen(self::FILE_PREFIX))
                : $basename;

            $rows[] = array_merge(
                ['slug' => $slug, 'path' => $path],
                $this->parseFile($contents),
            );
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return ['middlewares' => $rows, 'unreadable' => false];
    }

    /**
     * @param  array{type: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        foreach ($this->read($server)['middlewares'] as $row) {
            if (($row['slug'] ?? '') === $slug) {
                throw new \RuntimeException("Middleware `{$slug}` already exists.");
            }
        }
        $this->write($server, $slug, $fields, $emitter);
    }

    /**
     * @param  array{type: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $found = false;
        foreach ($this->read($server)['middlewares'] as $row) {
            if (($row['slug'] ?? '') === $slug) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("Middleware `{$slug}` not found.");
        }
        $this->write($server, $slug, $fields, $emitter);
    }

    /**
     * @throws \RuntimeException
     */
    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);
        $ssh = new SshConnection($server);
        $ssh->exec('sudo -n rm -f '.escapeshellarg($path), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to remove middleware file.');
        }
        $emit->success('Middleware '.$slug.' removed.');
    }

    /**
     * @param  array{type: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $name = self::FILE_PREFIX.$slug;
        $type = (string) ($fields['type'] ?? 'stripPrefix');
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported middleware type.');
        }

        $inner = match ($type) {
            'stripPrefix' => $this->stripPrefixBlock((string) ($fields['prefix'] ?? '/')),
            'redirectScheme' => $this->redirectSchemeBlock((string) ($fields['scheme'] ?? 'https')),
            'headers' => $this->headersBlock((string) ($fields['header_key'] ?? ''), (string) ($fields['header_value'] ?? '')),
            'basicAuth' => $this->basicAuthBlock((string) ($fields['users'] ?? '')),
            default => throw new \InvalidArgumentException('Unsupported middleware type.'),
        };

        return <<<YAML
# Managed by Dply — custom middleware (hot-reloaded).
http:
  middlewares:
    {$name}:
{$inner}
YAML;
    }

    /**
     * @return array{type: string, config_summary: string}
     */
    private function parseFile(string $contents): array
    {
        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable) {
            return ['type' => '?', 'config_summary' => ''];
        }
        $middlewares = is_array($parsed['http']['middlewares'] ?? null) ? $parsed['http']['middlewares'] : [];
        foreach ($middlewares as $name => $def) {
            if (! is_array($def)) {
                continue;
            }
            foreach (self::TYPES as $type) {
                if (isset($def[$type])) {
                    return ['type' => $type, 'config_summary' => (string) $name];
                }
            }
        }

        return ['type' => '?', 'config_summary' => ''];
    }

    /**
     * @param  array{type: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}  $fields
     */
    private function write(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $contents = $this->render($slug, $fields);
        $path = $this->pathForSlug($slug);
        $ssh = new SshConnection($server);
        $tmp = '/tmp/dply-traefik-mw-'.bin2hex(random_bytes(4));
        $encoded = base64_encode($contents);
        $ssh->exec(sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null && sudo -n install -m 0644 -T %s %s && sudo -n rm -f %s',
            escapeshellarg($encoded),
            escapeshellarg($tmp),
            escapeshellarg($tmp),
            escapeshellarg($path),
            escapeshellarg($tmp),
        ), 20);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to write middleware file.');
        }
        $emit->success('Middleware saved; file provider will hot-reload.');
    }

    private function stripPrefixBlock(string $prefix): string
    {
        $prefix = '/'.trim($prefix, '/');

        return "      stripPrefix:\n        prefixes:\n          - \"{$prefix}\"\n";
    }

    private function redirectSchemeBlock(string $scheme): string
    {
        $scheme = in_array($scheme, ['https', 'http'], true) ? $scheme : 'https';

        return "      redirectScheme:\n        scheme: {$scheme}\n        permanent: true\n";
    }

    private function headersBlock(string $key, string $value): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Header name is required for headers middleware.');
        }

        return "      headers:\n        customRequestHeaders:\n          {$key}: \"{$value}\"\n";
    }

    private function basicAuthBlock(string $users): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($users)) ?: [])));
        if ($lines === []) {
            throw new \InvalidArgumentException('Add at least one user:password line for basicAuth.');
        }
        $yamlUsers = implode("\n          - ", array_map(
            static fn (string $line): string => '"'.str_replace('"', '\\"', $line).'"',
            $lines,
        ));

        return "      basicAuth:\n        users:\n          - {$yamlUsers}\n";
    }

    private function pathForSlug(string $slug): string
    {
        return self::DYNAMIC_DIR.'/'.self::FILE_PREFIX.$slug.'.yml';
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new \InvalidArgumentException('Middleware slug is required.');
        }

        return $slug;
    }
}
