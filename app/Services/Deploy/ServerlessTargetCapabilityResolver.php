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
     *     default_package: string,
     *     host_label: string
     * }
     */
    public function forSite(Site $site): array
    {
        $site->loadMissing('server');

        return $this->forServer($site->server);
    }

    /**
     * @return array{
     *     target: string,
     *     supports_runtime_detection: bool,
     *     supports_php_runtime: bool,
     *     supports_node_runtime: bool,
     *     default_runtime: string,
     *     default_entrypoint: string,
     *     default_package: string,
     *     host_label: string
     * }
     */
    public function forServer(?Server $server): array
    {

        if ($server instanceof Server && $server->isDigitalOceanFunctionsHost()) {
            return [
                'target' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
                'supports_runtime_detection' => true,
                'supports_php_runtime' => false,
                'supports_node_runtime' => true,
                'default_runtime' => 'nodejs:18',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
                'host_label' => 'DigitalOcean Functions',
            ];
        }

        if ($server instanceof Server && $server->isAwsLambdaHost()) {
            return [
                'target' => Server::HOST_KIND_AWS_LAMBDA,
                'supports_runtime_detection' => true,
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'provided.al2023',
                'default_entrypoint' => 'public/index.php',
                'default_package' => '',
                'host_label' => 'AWS Lambda',
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
            'host_label' => 'Unknown',
        ];
    }
}
