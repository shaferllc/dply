<?php

namespace App\Services\Servers\Bootstrap;

use App\Models\Server;

class ServerBootstrapStrategyResolver
{
    /**
     * @param  iterable<ServerBootstrapStrategy>  $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
    ) {}

    public function for(Server $server): ServerBootstrapStrategy
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($server)) {
                return $strategy;
            }
        }

        throw new \RuntimeException('Unsupported host kind ['.$server->hostKind().'] for server bootstrap.');
    }
}
