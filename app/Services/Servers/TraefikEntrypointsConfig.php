<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * CRUD for Traefik static entryPoints in traefik.yml (requires restart).
 */
class TraefikEntrypointsConfig
{
    private const REMOTE_PATH = '/etc/traefik/traefik.yml';

    /** @var list<string> */
    public const LOCKED_NAMES = ['web', 'traefik', 'metrics'];

    /**
     * @return array{entrypoints: list<array{name: string, address: string, locked: bool}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $parsed = $this->loadParsed($server);
        } catch (\Throwable) {
            return ['entrypoints' => [], 'unreadable' => true];
        }

        $eps = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        $rows = [];
        foreach ($eps as $name => $def) {
            if (! is_string($name) || ! is_array($def)) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'address' => (string) ($def['address'] ?? ''),
                'locked' => in_array($name, self::LOCKED_NAMES, true),
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['entrypoints' => $rows, 'unreadable' => false];
    }

    /**
     * @throws \RuntimeException
     */
    public function add(Server $server, string $name, string $address, ?ConsoleEmitter $emitter = null): void
    {
        $name = $this->normalizeName($name);
        $address = trim($address);
        if ($address === '') {
            throw new \InvalidArgumentException('Listen address is required (e.g. :8080).');
        }

        $parsed = $this->loadParsed($server);
        $eps = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        if (array_key_exists($name, $eps)) {
            throw new \RuntimeException("Entry point `{$name}` already exists.");
        }
        $eps[$name] = ['address' => $address];
        $parsed['entryPoints'] = $eps;
        $this->persist($server, $parsed, $emitter, 'add entry point '.$name);
    }

    /**
     * @throws \RuntimeException
     */
    public function save(Server $server, string $name, string $address, ?ConsoleEmitter $emitter = null): void
    {
        $name = $this->normalizeName($name);
        $address = trim($address);
        if ($address === '') {
            throw new \InvalidArgumentException('Listen address is required.');
        }

        $parsed = $this->loadParsed($server);
        $eps = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        if (! array_key_exists($name, $eps)) {
            throw new \RuntimeException("Entry point `{$name}` not found.");
        }
        $eps[$name]['address'] = $address;
        $parsed['entryPoints'] = $eps;
        $this->persist($server, $parsed, $emitter, 'save entry point '.$name);
    }

    /**
     * @throws \RuntimeException
     */
    public function remove(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $name = $this->normalizeName($name);
        if (in_array($name, self::LOCKED_NAMES, true)) {
            throw new \RuntimeException("Cannot remove the required `{$name}` entry point.");
        }

        $parsed = $this->loadParsed($server);
        $eps = is_array($parsed['entryPoints'] ?? null) ? $parsed['entryPoints'] : [];
        if (! array_key_exists($name, $eps)) {
            throw new \RuntimeException("Entry point `{$name}` not found.");
        }
        unset($eps[$name]);
        $parsed['entryPoints'] = $eps;
        $this->persist($server, $parsed, $emitter, 'remove entry point '.$name);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function persist(Server $server, array $parsed, ?ConsoleEmitter $emitter, string $label): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $emit->step('traefik-entrypoints', $label);
        $parsed = app(TraefikStaticConfigOptions::class)->ensureDplyTraefikStaticDefaults($server, $parsed);
        $yaml = Yaml::dump($parsed, 6, 2, Yaml::DUMP_NULL_AS_TILDE);
        app(TraefikStaticConfigOptions::class)->installYamlAndRestart($server, $yaml, $emitter);
        $emit->success('Traefik restarted with updated entry points.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadParsed(Server $server): array
    {
        $ssh = new SshConnection($server);
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            throw new \RuntimeException('Could not read traefik.yml.');
        }
        $parsed = Yaml::parse($contents);
        if (! is_array($parsed)) {
            throw new \RuntimeException('traefik.yml is not a valid YAML document.');
        }

        return $parsed;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? $name;
        $name = trim($name, '-');
        if ($name === '') {
            throw new \InvalidArgumentException('Entry point name is required.');
        }

        return $name;
    }
}
