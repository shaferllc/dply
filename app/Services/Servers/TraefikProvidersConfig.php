<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * Optional Traefik config providers (Docker, Kubernetes, Consul) in traefik.yml.
 * File provider stays managed via {@see TraefikStaticConfigOptions}.
 */
class TraefikProvidersConfig
{
    private const REMOTE_PATH = '/etc/traefik/traefik.yml';

    /**
     * @return array{
     *     values: array<string, string>,
     *     configured: list<array{key: string, label: string, summary: string}>,
     *     unreadable: bool
     * }
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        $defaults = $this->defaults();
        $configured = [['key' => 'file', 'label' => 'File', 'summary' => __('Always enabled via static config')]];

        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['values' => $defaults, 'configured' => $configured, 'unreadable' => true];
            }
            $parsed = Yaml::parse($contents);
            if (! is_array($parsed)) {
                return ['values' => $defaults, 'configured' => $configured, 'unreadable' => false];
            }
        } catch (\Throwable) {
            return ['values' => $defaults, 'configured' => $configured, 'unreadable' => true];
        }

        $providers = is_array($parsed['providers'] ?? null) ? $parsed['providers'] : [];
        $fileDir = is_array($providers['file'] ?? null)
            ? (string) ($providers['file']['directory'] ?? '/etc/traefik/dynamic')
            : '/etc/traefik/dynamic';
        $configured[0]['summary'] = $fileDir;

        if (is_array($providers['docker'] ?? null)) {
            $docker = $providers['docker'];
            $defaults['docker_enabled'] = '1';
            $defaults['docker_endpoint'] = (string) ($docker['endpoint'] ?? 'unix:///var/run/docker.sock');
            $defaults['docker_exposedByDefault'] = ! empty($docker['exposedByDefault']) ? '1' : '0';
            $configured[] = [
                'key' => 'docker',
                'label' => 'Docker',
                'summary' => $defaults['docker_endpoint'],
            ];
        }

        if (is_array($providers['kubernetes'] ?? null)) {
            $k8s = $providers['kubernetes'];
            $defaults['k8s_enabled'] = '1';
            $defaults['k8s_kubeconfig'] = (string) ($k8s['kubeconfig'] ?? '');
            $defaults['k8s_inCluster'] = ! empty($k8s['inCluster']) ? '1' : '0';
            $configured[] = [
                'key' => 'kubernetes',
                'label' => 'Kubernetes',
                'summary' => $defaults['k8s_inCluster'] === '1'
                    ? __('inCluster')
                    : ($defaults['k8s_kubeconfig'] !== '' ? $defaults['k8s_kubeconfig'] : __('kubeconfig not set')),
            ];
        }

        if (is_array($providers['consul'] ?? null)) {
            $consul = $providers['consul'];
            $defaults['consul_enabled'] = '1';
            $defaults['consul_endpoint'] = (string) ($consul['endpoints'][0] ?? $consul['endpoint'] ?? '127.0.0.1:8500');
            $configured[] = [
                'key' => 'consul',
                'label' => 'Consul',
                'summary' => $defaults['consul_endpoint'],
            ];
        }

        return ['values' => $defaults, 'configured' => $configured, 'unreadable' => false];
    }

    /**
     * @param  array<string, mixed> $values
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            throw new \RuntimeException('Could not read traefik.yml from the server.');
        }

        try {
            $parsed = Yaml::parse($contents);
            if (! is_array($parsed)) {
                $parsed = [];
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Existing traefik.yml is not valid YAML.');
        }

        $providers = is_array($parsed['providers'] ?? null) ? $parsed['providers'] : [];
        if (! is_array($providers['file'] ?? null)) {
            $providers['file'] = [
                'directory' => '/etc/traefik/dynamic',
                'watch' => true,
            ];
        }

        $this->applyDocker($providers, $values);
        $this->applyKubernetes($providers, $values);
        $this->applyConsul($providers, $values);

        $parsed['providers'] = $providers;
        $parsed = app(TraefikStaticConfigOptions::class)->ensureDplyTraefikStaticDefaults($server, $parsed);
        $newContents = Yaml::dump($parsed, 6, 2, Yaml::DUMP_NULL_AS_TILDE);

        app(TraefikStaticConfigOptions::class)->installYamlAndRestart($server, $newContents, $emitter);
    }

    /**
     * Install Docker engine so the Docker provider can reach the socket.
     *
     * @throws \RuntimeException
     */
    public function installDocker(Server $server, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('traefik-docker', 'Installing Docker (docker.io)');
        $script = <<<'BASH'
set -euo pipefail
if command -v docker >/dev/null 2>&1 && [ -S /var/run/docker.sock ]; then
  echo "[dply] Docker already installed."
  exit 0
fi
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends docker.io
systemctl enable --now docker 2>/dev/null || true
[ -S /var/run/docker.sock ] || { echo "[dply] docker.sock missing after install" >&2; exit 127; }
BASH;
        $out = $ssh->exec('sudo -n bash -c '.escapeshellarg($script), 120);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error(trim($out) !== '' ? trim($out) : 'Docker install failed.');
            throw new \RuntimeException('Docker install failed on the server.');
        }
        $emit->success('Docker is installed and the socket is available.');
    }

    /**
     * @param  array<string, mixed> $providers
     * @param  array<string, mixed> $values
     */
    private function applyDocker(array &$providers, array $values): void
    {
        if (! $this->bool($values['docker_enabled'] ?? '0')) {
            unset($providers['docker']);

            return;
        }
        $providers['docker'] = array_filter([
            'endpoint' => trim((string) ($values['docker_endpoint'] ?? 'unix:///var/run/docker.sock')) ?: 'unix:///var/run/docker.sock',
            'exposedByDefault' => $this->bool($values['docker_exposedByDefault'] ?? '0'),
            'watch' => true,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed> $providers
     * @param  array<string, mixed> $values
     */
    private function applyKubernetes(array &$providers, array $values): void
    {
        if (! $this->bool($values['k8s_enabled'] ?? '0')) {
            unset($providers['kubernetes']);

            return;
        }
        $inCluster = $this->bool($values['k8s_inCluster'] ?? '0');
        $kubeconfig = trim((string) ($values['k8s_kubeconfig'] ?? ''));
        $block = ['watch' => true];
        if ($inCluster) {
            $block['inCluster'] = true;
        } elseif ($kubeconfig !== '') {
            $block['kubeconfig'] = $kubeconfig;
        }
        $providers['kubernetes'] = $block;
    }

    /**
     * @param  array<string, mixed> $providers
     * @param  array<string, mixed> $values
     */
    private function applyConsul(array &$providers, array $values): void
    {
        if (! $this->bool($values['consul_enabled'] ?? '0')) {
            unset($providers['consul']);

            return;
        }
        $endpoint = trim((string) ($values['consul_endpoint'] ?? '127.0.0.1:8500')) ?: '127.0.0.1:8500';
        $providers['consul'] = [
            'endpoints' => [$endpoint],
        ];
    }

    private function bool(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'docker_enabled' => '0',
            'docker_endpoint' => 'unix:///var/run/docker.sock',
            'docker_exposedByDefault' => '0',
            'k8s_enabled' => '0',
            'k8s_kubeconfig' => '',
            'k8s_inCluster' => '0',
            'consul_enabled' => '0',
            'consul_endpoint' => '127.0.0.1:8500',
        ];
    }
}
