<?php

namespace App\Services\Servers\Bootstrap;

use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;

class DockerHostBootstrapStrategy implements ServerBootstrapStrategy
{
    public function __construct(
        private readonly ServerProvisionCommandBuilder $builder,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->isDockerHost();
    }

    public function build(Server $server): array
    {
        return $this->builder->build($server);
    }

    public function buildArtifacts(Server $server): array
    {
        return $this->builder->buildArtifacts($server);
    }
}
