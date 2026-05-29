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
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return array{containers: list<array{id: string, name: string, image: string, status: string, state: string}>, error: ?string}
     */
    public function listContainers(Server $server): array
    {
        if (! $this->dockerCliPresent($server)) {
            return ['containers' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        try {
            $out = $this->executor->runInlineBash(
                $server,
                'docker-ps-all',
                <<<'BASH'
if ! command -v docker >/dev/null 2>&1; then
  echo "__DPLY_DOCKER_MISSING__"
  exit 0
fi
docker ps -a --format '{{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.State}}' 2>/dev/null | head -n 500
BASH,
                90,
                true,
            )->getBuffer();
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
            $out = $this->executor->runInlineBash(
                $server,
                'docker-images',
                <<<'BASH'
if ! command -v docker >/dev/null 2>&1; then
  echo "__DPLY_DOCKER_MISSING__"
  exit 0
fi
docker images --format '{{.ID}}\t{{.Repository}}\t{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}' 2>/dev/null | head -n 500
BASH,
                90,
                true,
            )->getBuffer();
        } catch (\Throwable $e) {
            return ['images' => [], 'error' => $e->getMessage()];
        }

        if (str_contains($out, '__DPLY_DOCKER_MISSING__')) {
            return ['images' => [], 'error' => __('Docker CLI is not installed on this server.')];
        }

        return ['images' => $this->parseImageLines($out), 'error' => null];
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

    /**
     * @return list<array{id: string, name: string, image: string, status: string, state: string}>
     */
    private function parseContainerLines(string $output): array
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
                'name' => $parts[1],
                'image' => $parts[2],
                'status' => $parts[3],
                'state' => $parts[4] ?? '',
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
}
