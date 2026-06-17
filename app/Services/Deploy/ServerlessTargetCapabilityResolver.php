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
     *     supports_python_runtime: bool,
     *     supports_go_runtime: bool,
     *     default_runtime: string,
     *     default_python_runtime: string,
     *     default_entrypoint: string,
     *     default_package: string,
     *     host_label: string
     * }
     */
    /** @return array<string, mixed> */
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
     *     supports_python_runtime: bool,
     *     supports_go_runtime: bool,
     *     default_runtime: string,
     *     default_python_runtime: string,
     *     default_entrypoint: string,
     *     default_package: string,
     *     host_label: string
     * }
     */
    /** @return array<string, mixed> */
    public function forServer(?Server $server): array
    {

        if ($server instanceof Server && $server->isDigitalOceanFunctionsHost()) {
            return $this->forDigitalOceanFunctions();
        }

        if ($server instanceof Server && $server->isAwsLambdaHost()) {
            return [
                'target' => Server::HOST_KIND_AWS_LAMBDA,
                'supports_runtime_detection' => true,
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'supports_python_runtime' => true,
                'supports_go_runtime' => true,
                'default_runtime' => 'provided.al2023',
                'default_python_runtime' => 'python3.12',
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
            'supports_python_runtime' => false,
            'supports_go_runtime' => false,
            'default_runtime' => '',
            'default_python_runtime' => 'python3.12',
            'default_entrypoint' => '',
            'default_package' => '',
            'host_label' => 'Unknown',
        ];
    }

    /**
     * The DigitalOcean Functions capability map, independent of any Server
     * row. The serverless create flow runs detection before the host
     * namespace exists, so it resolves capabilities directly via this.
     *
     * @return array{
     *     target: string,
     *     supports_runtime_detection: bool,
     *     supports_php_runtime: bool,
     *     supports_node_runtime: bool,
     *     supports_python_runtime: bool,
     *     supports_go_runtime: bool,
     *     default_runtime: string,
     *     default_python_runtime: string,
     *     default_entrypoint: string,
     *     default_package: string,
     *     host_label: string
     * }
     */
    /** @return array<string, mixed> */
    public function forDigitalOceanFunctions(): array
    {
        return [
            'target' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'supports_runtime_detection' => true,
            // DigitalOcean Functions (managed Apache OpenWhisk) ships
            // exactly four tenant runtimes: Node.js, Python, PHP, and Go.
            // PHP runs natively — no Bref needed; that's the Lambda path.
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'supports_python_runtime' => true,
            'supports_go_runtime' => true,
            'default_runtime' => 'nodejs:18',
            'default_python_runtime' => 'python3.12',
            // OpenWhisk `exec.main` — the handler function name, not a
            // file. dply's PHP/Node function templates export `main`.
            'default_entrypoint' => 'main',
            'default_package' => 'default',
            'host_label' => 'DigitalOcean Functions',
        ];
    }
}
