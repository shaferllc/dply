<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Carbon;

final class DockerWorkspaceViewData
{
    /**
     * @return array{
     *     docker: array<string, mixed>,
     *     checked_at: ?Carbon,
     *     docker_present: bool,
     * }
     */
    public static function for(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $docker = is_array($meta['manage_docker'] ?? null) ? $meta['manage_docker'] : [];
        if ($docker === []) {
            $tool = is_array($meta['manage_tools']['docker'] ?? null) ? $meta['manage_tools']['docker'] : [];
            $docker = [
                'present' => ! empty($tool['present']),
                'version' => is_string($tool['version'] ?? null) ? $tool['version'] : null,
                'containers_running' => 0,
                'containers_stopped' => 0,
                'images_count' => 0,
            ];
        }

        $checkedAt = null;
        if (is_string($meta['inventory_checked_at'] ?? null) && $meta['inventory_checked_at'] !== '') {
            try {
                $checkedAt = Carbon::parse($meta['inventory_checked_at']);
            } catch (\Throwable) {
                $checkedAt = null;
            }
        }

        return [
            'docker' => $docker,
            'checked_at' => $checkedAt,
            'docker_present' => ! empty($docker['present']),
        ];
    }
}
