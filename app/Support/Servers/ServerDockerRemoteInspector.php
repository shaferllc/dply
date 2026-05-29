<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * On-demand Docker inventory over SSH for the server Docker workspace.
 */
final class ServerDockerRemoteInspector
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return array{containers: list<array{id: string, name: string, image: string, status: string, state: string, ports: string}>, error: ?string}
     */
    public function listContainers(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['containers' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-ps-all', <<<'BASH'
docker ps -a --format '{{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.State}}\t{{.Ports}}' 2>/dev/null | head -n 500
BASH);
        } catch (\Throwable $e) {
            return ['containers' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['containers' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['containers' => $this->parseContainerLines($out), 'error' => null];
    }

    /**
     * @return array{images: list<array{id: string, repository: string, tag: string, size: string, created: string}>, error: ?string}
     */
    public function listImages(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['images' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-images', <<<'BASH'
docker images --format '{{.ID}}\t{{.Repository}}\t{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}' 2>/dev/null | head -n 500
BASH);
        } catch (\Throwable $e) {
            return ['images' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['images' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['images' => $this->parseImageLines($out), 'error' => null];
    }

    /**
     * @return array{volumes: list<array{name: string, driver: string, scope: string}>, error: ?string}
     */
    public function listVolumes(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['volumes' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-volumes', <<<'BASH'
docker volume ls --format '{{.Name}}\t{{.Driver}}\t{{.Scope}}' 2>/dev/null | head -n 500
BASH);
        } catch (\Throwable $e) {
            return ['volumes' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['volumes' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['volumes' => $this->parseVolumeLines($out), 'error' => null];
    }

    /**
     * @return array{networks: list<array{id: string, name: string, driver: string, scope: string}>, error: ?string}
     */
    public function listNetworks(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['networks' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-networks', <<<'BASH'
docker network ls --format '{{.ID}}\t{{.Name}}\t{{.Driver}}\t{{.Scope}}' 2>/dev/null | head -n 500
BASH);
        } catch (\Throwable $e) {
            return ['networks' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['networks' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['networks' => $this->parseNetworkLines($out), 'error' => null];
    }

    /**
     * @return array{projects: list<array{name: string, status: string, config: string}>, error: ?string}
     */
    public function listComposeProjects(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['projects' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-compose-ls', <<<'BASH'
if docker compose version >/dev/null 2>&1; then
  docker compose ls --format '{{.Name}}\t{{.Status}}\t{{.ConfigFiles}}' 2>/dev/null | head -n 200
elif command -v docker-compose >/dev/null 2>&1; then
  docker-compose ls 2>/dev/null | tail -n +2 | awk '{print $1 "\t" $2 "\t" $3}'
else
  echo "__DPLY_COMPOSE_MISSING__"
fi
BASH, 120);
        } catch (\Throwable $e) {
            return ['projects' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['projects' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        if (str_contains($out, '__DPLY_COMPOSE_MISSING__')) {
            return ['projects' => [], 'error' => __('Docker Compose plugin is not installed on this server.')];
        }

        return ['projects' => $this->parseComposeLines($out), 'error' => null];
    }

    /**
     * @return array{rows: list<array{type: string, total: string, active: string, size: string, reclaimable: string}>, error: ?string}
     */
    public function systemDiskUsage(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['rows' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->runDockerScript($server, 'docker-system-df', <<<'BASH'
docker system df --format '{{.Type}}\t{{.TotalCount}}\t{{.Active}}\t{{.Size}}\t{{.Reclaimable}}' 2>/dev/null
BASH, 90);
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['rows' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['rows' => $this->parseSystemDfLines($out), 'error' => null];
    }

    /**
     * @return array{logs: string, error: ?string}
     */
    public function containerLogs(Server $server, string $containerId, int $lines = 200): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['logs' => '', 'error' => __('Docker CLI is not installed on this server.')];
        }

        if (! $this->isValidContainerRef($containerId)) {
            return ['logs' => '', 'error' => __('Invalid container.')];
        }

        $lines = max(10, min(2000, $lines));
        $escaped = escapeshellarg($containerId);

        try {
            $out = $this->runDockerScript($server, 'docker-logs-'.$containerId, <<<BASH
docker logs --tail {$lines} {$escaped} 2>&1
BASH, 120);
        } catch (\Throwable $e) {
            return ['logs' => '', 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['logs' => '', 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['logs' => $out, 'error' => null];
    }

    /**
     * @return array{inspect: string, error: ?string}
     */
    public function containerInspect(Server $server, string $containerId): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['inspect' => '', 'error' => __('Docker CLI is not installed on this server.')];
        }

        if (! $this->isValidContainerRef($containerId)) {
            return ['inspect' => '', 'error' => __('Invalid container.')];
        }

        $escaped = escapeshellarg($containerId);

        try {
            $out = $this->runDockerScript($server, 'docker-inspect-'.$containerId, <<<BASH
docker inspect {$escaped} 2>&1
BASH, 90);
        } catch (\Throwable $e) {
            return ['inspect' => '', 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['inspect' => '', 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['inspect' => $out, 'error' => null];
    }

    public function dockerCliPresent(Server $server): bool
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $manageDocker = is_array($meta['manage_docker'] ?? null) ? $meta['manage_docker'] : [];
        if (! empty($manageDocker['present'])) {
            return true;
        }

        $manageTools = is_array($meta['manage_tools'] ?? null) ? $meta['manage_tools'] : [];
        $dockerTool = is_array($manageTools['docker'] ?? null) ? $manageTools['docker'] : [];

        return ! empty($dockerTool['present']);
    }

    public function isValidContainerRef(string $ref): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/', $ref);
    }

    public function isValidImageRef(string $ref): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9@][a-zA-Z0-9@:._\/-]{0,255}$/', $ref);
    }

    private function runDockerScript(Server $server, string $taskName, string $body, int $timeout = 90): string
    {
        $script = <<<BASH
if ! command -v docker >/dev/null 2>&1; then
  echo "__DPLY_DOCKER_MISSING__"
  exit 0
fi
{$body}
BASH;

        return $this->executor->runInlineBash($server, $taskName, $script, $timeout, true)->getBuffer();
    }

    /**
     * @return list<array{id: string, name: string, image: string, status: string, state: string, ports: string}>
     */
    private function parseContainerLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 6);
            if (count($parts) < 4) {
                continue;
            }

            $rows[] = [
                'id' => $parts[0],
                'name' => $parts[1],
                'image' => $parts[2],
                'status' => $parts[3],
                'state' => $parts[4] ?? '',
                'ports' => $parts[5] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, repository: string, tag: string, size: string, created: string}>
     */
    private function parseImageLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 5);
            if (count($parts) < 4) {
                continue;
            }

            $rows[] = [
                'id' => $parts[0],
                'repository' => $parts[1],
                'tag' => $parts[2],
                'size' => $parts[3],
                'created' => $parts[4] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, driver: string, scope: string}>
     */
    private function parseVolumeLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);
            if (count($parts) < 2) {
                continue;
            }

            $rows[] = [
                'name' => $parts[0],
                'driver' => $parts[1],
                'scope' => $parts[2] ?? 'local',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, name: string, driver: string, scope: string}>
     */
    private function parseNetworkLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 4);
            if (count($parts) < 3) {
                continue;
            }

            $rows[] = [
                'id' => $parts[0],
                'name' => $parts[1],
                'driver' => $parts[2],
                'scope' => $parts[3] ?? 'local',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, status: string, config: string}>
     */
    private function parseComposeLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 3);
            if ($parts[0] === '') {
                continue;
            }

            $rows[] = [
                'name' => $parts[0],
                'status' => $parts[1] ?? '',
                'config' => $parts[2] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{type: string, total: string, active: string, size: string, reclaimable: string}>
     */
    private function parseSystemDfLines(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 5);
            if (count($parts) < 4) {
                continue;
            }

            $rows[] = [
                'type' => $parts[0],
                'total' => $parts[1],
                'active' => $parts[2],
                'size' => $parts[3],
                'reclaimable' => $parts[4] ?? '',
            ];
        }

        return $rows;
    }
}
