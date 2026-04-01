<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Server;
use App\Models\Site;

final class ServerlessTargetCapabilityResolver
{
    /**
     * @return array{
     *     target: string,
     *     supports_runtime_detection: bool,
     *     supports_php_runtime: bool,
     *     supports_node_runtime: bool,
     *     default_runtime: string,
     *     default_entrypoint: string,
     *     default_package: string
     * }
     */
    public function forSite(Site $site): array
    {
        $site->loadMissing('server');

        $server = $site->server;

        if ($server instanceof Server && $server->isDigitalOceanFunctionsHost()) {
            return [
                'target' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'supports_runtime_detection' => true,
                'supports_php_runtime' => false,
                'supports_node_runtime' => true,
                'default_runtime' => 'nodejs:18',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ];
        }

        return [
            'target' => 'unknown',
            'supports_runtime_detection' => false,
            'supports_php_runtime' => false,
            'supports_node_runtime' => false,
            'default_runtime' => '',
            'default_entrypoint' => '',
            'default_package' => '',
        ];
    }
}
