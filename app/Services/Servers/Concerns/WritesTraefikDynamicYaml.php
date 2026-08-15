<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

trait WritesTraefikDynamicYaml
{
    protected function writeTraefikDynamicFile(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter, string $label): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $emit->step('traefik-dynamic', $label.' → '.$path);
        $ssh = new SshConnection($server);
        $tmp = '/tmp/dply-traefik-'.bin2hex(random_bytes(4));
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
            throw new \RuntimeException('Failed to write '.$path);
        }
        $emit->success('Saved; Traefik file provider will hot-reload.');
    }

    protected function removeTraefikDynamicFile(Server $server, string $path, ?ConsoleEmitter $emitter): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);
        $ssh->exec('sudo -n rm -f '.escapeshellarg($path), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to remove '.$path);
        }
        $emit->success('Removed '.$path);
    }

    protected function normalizeTraefikSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new \InvalidArgumentException('Slug is required.');
        }

        return $slug;
    }

    /**
     * @param  list<string>|string  $csv
     * @return list<string>
     */
    protected function csvList(mixed $csv): array
    {
        if (is_string($csv)) {
            $csv = preg_split('/[\s,]+/', trim($csv)) ?: [];
        }
        if (! is_array($csv)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $csv)));
    }
}
