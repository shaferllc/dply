<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

/**
 * Helpers for non-interactive container shell sessions in the Docker workspace.
 *
 * Full PTY / docker exec -it in the browser needs WebSocket infrastructure;
 * this support class powers the rolling session UI and the local SSH fallback.
 */
final class DockerContainerShellSupport
{
    public static function remoteExecCommand(string $containerId, string $command): string
    {
        $cidEsc = escapeshellarg($containerId);
        $cmdEsc = escapeshellarg($command);

        return "sudo -n docker exec {$cidEsc} sh -c {$cmdEsc} 2>&1";
    }

    public static function localInteractiveSshOneLiner(Server $server, string $containerId): string
    {
        $user = trim((string) ($server->ssh_user ?: 'dply'));
        if ($user === '') {
            $user = 'dply';
        }

        $host = trim((string) ($server->ip_address ?? ''));
        if ($host === '') {
            $host = trim((string) ($server->name ?? 'your-server'));
        }

        $cidEsc = escapeshellarg($containerId);

        return sprintf('ssh -t %s@%s "sudo docker exec -it %s sh"', $user, $host, $cidEsc);
    }

    /**
     * @return list<array{label: string, cmd: string}>
     */
    public static function quickActions(): array
    {
        return [
            ['label' => 'pwd', 'cmd' => 'pwd'],
            ['label' => 'ls -la', 'cmd' => 'ls -la'],
            ['label' => 'env', 'cmd' => 'env | sort'],
            ['label' => 'ps', 'cmd' => 'ps auxww 2>/dev/null || ps aux'],
        ];
    }
}
