<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\TraefikAdminUrl;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads traefik.yml on the server and resolves a reachable localhost API base URL.
 */
class TraefikAdminApiResolver
{
    use PrivilegedRemoteFileWrites;

    public const REMOTE_PATH = '/etc/traefik/traefik.yml';

    /**
     * @return array{base_url: string, dashboard_enabled: bool, insecure_enabled: bool}
     *
     * @throws \RuntimeException
     */
    /** @return array<string, mixed> */
    public function resolve(Server $server): array
    {
        if ($server->edgeProxy() !== 'traefik') {
            throw new \RuntimeException('This server does not have Traefik as its edge proxy.');
        }

        $parsed = $this->loadParsed($server);
        $candidates = $this->candidateBaseUrls($parsed);

        foreach ($candidates as $baseUrl) {
            if ($this->probeApi($server, $baseUrl)) {
                return [
                    'base_url' => $baseUrl,
                    'dashboard_enabled' => TraefikAdminUrl::apiDashboardEnabled($parsed),
                    'insecure_enabled' => TraefikAdminUrl::apiInsecureEnabled($parsed),
                ];
            }
        }

        throw $this->buildUnreachableException($server, $parsed, $candidates);
    }

    /**
     * @param  array<string, mixed> $parsed
     * @return list<string>
     */
    private function candidateBaseUrls(array $parsed): array
    {
        $urls = array_filter([
            TraefikAdminUrl::fromStaticConfig($parsed),
            TraefikAdminUrl::fromAddress(TraefikAdminUrl::DEFAULT_ADDRESS),
        ]);

        return array_values(array_unique(array_map(
            static fn (?string $url): string => rtrim((string) $url, '/'),
            $urls,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function loadParsed(Server $server): array
    {
        $ssh = new SshConnection($server);
        $contents = $ssh->exec(
            $this->privilegedCommand($server, 'cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null'),
            15,
        );
        $contents = str_replace("\0", '', (string) $contents);
        if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException(
                'Could not read /etc/traefik/traefik.yml over SSH. Check deploy-user sudo and that Traefik is installed.',
            );
        }

        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable $e) {
            throw new \RuntimeException('traefik.yml on the server is not valid YAML: '.$e->getMessage());
        }

        return is_array($parsed) ? $parsed : [];
    }

    private function probeApi(Server $server, string $baseUrl): bool
    {
        $probeUrl = rtrim($baseUrl, '/').'/api/overview';
        $quoted = escapeshellarg($probeUrl);
        $script = <<<BASH
code=\$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 {$quoted} 2>/dev/null || echo 000)
test "\$code" = "200" || test "\$code" = "401" || test "\$code" = "403"
BASH;

        $ssh = new SshConnection($server);
        $ssh->exec($this->privilegedCommand($server, $script), 12);

        return $ssh->lastExecExitCode() === 0;
    }

    /**
     * @param  array<string, mixed> $parsed
     * @param  array<string, mixed> $candidates
     */
    private function buildUnreachableException(Server $server, array $parsed, array $candidates): \RuntimeException
    {
        $ssh = new SshConnection($server);
        $unitCheck = $ssh->exec($this->privilegedCommand(
            $server,
            '(systemctl is-active --quiet traefik || systemctl is-active --quiet traefik.service) && echo active || echo inactive',
        ), 10);
        $unitState = trim($unitCheck) === 'active' ? 'active' : 'inactive';

        $portCheck = trim($ssh->exec($this->privilegedCommand(
            $server,
            'ss -ltn -H "sport = :9094" 2>/dev/null | head -n 1',
        ), 10));
        $portOpen = $portCheck !== '';

        $lines = [
            __('Traefik\'s localhost API is not responding on the server.'),
            __('Tried: :urls', ['urls' => implode(', ', $candidates)]),
            __('systemd: :state', ['state' => $unitState]),
            __('Port 9094 listening: :state', ['state' => $portOpen ? __('yes') : __('no')]),
        ];

        if (! TraefikAdminUrl::hasTraefikEntryPoint($parsed)) {
            $lines[] = __('Static config is missing the traefik entry point (127.0.0.1:9094). Use Repair API on :port on the Traefik Overview tab.', ['port' => '9094']);
        } elseif (! TraefikAdminUrl::apiDashboardEnabled($parsed)) {
            $lines[] = __('Enable API dashboard in Traefik static config, then restart Traefik.');
        } elseif (! TraefikAdminUrl::apiInsecureEnabled($parsed)) {
            $lines[] = __('Enable API insecure (localhost only) or bind the traefik entry point to 127.0.0.1:9094, then restart Traefik.');
        } elseif ($unitState === 'inactive') {
            $lines[] = __('Traefik is stopped. On the Traefik Overview tab use Start Traefik or Repair API on :port, or run sudo systemctl enable --now traefik on the server.', ['port' => '9094']);
        } else {
            $lines[] = __('Traefik may be running but the API is not on :9094. Use Repair API on :port on the Traefik Overview tab.', ['port' => '9094']);
        }

        return new \RuntimeException(implode("\n\n", $lines));
    }
}
