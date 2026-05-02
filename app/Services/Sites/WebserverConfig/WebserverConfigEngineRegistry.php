<?php

namespace App\Services\Sites\WebserverConfig;

class WebserverConfigEngineRegistry
{
    /**
     * @param  iterable<WebserverConfigEngineInterface>  $engines
     */
    public function __construct(
        private readonly iterable $engines,
    ) {}

    public function for(string $webserver): WebserverConfigEngineInterface
    {
        foreach ($this->engines as $engine) {
            if ($engine->webserver() === $webserver) {
                return $engine;
            }
        }

        throw new \RuntimeException('Unsupported web server ['.$webserver.'] for config editor.');
    }
}
