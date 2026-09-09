<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\User;

/**
 * Builds report + timeline for the access graph workspace in one query batch.
 */
final class ServerSshAccessWorkspaceData
{
    public function __construct(
        private ServerSshAccessGraph $graph,
        private ServerSshAccessTimeline $timeline,
        private ServerAccessMap $accessMap,
    ) {}

    /**
     * @return array{report: array<string, mixed>, timeline: array<string, mixed>, access_map: array<string, mixed>}
     */
    public function for(Server $server, ?User $viewer, string $range = '30d'): array
    {
        $context = ServerSshAccessContext::load($server);
        $report = $this->graph->forServer($server, $context);

        return [
            'report' => $report,
            'timeline' => $this->timeline->forServer($server, $viewer, $range, $context),
            'access_map' => $this->accessMap->build($server, $report['rows']),
        ];
    }
}
