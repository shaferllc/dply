<?php

namespace App\Services\ConfigRevisions\Diff;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Map ConfigRevision `kind` strings to a diff renderer. Single point
 * of registration so adding a new kind (supervisor program, .env, ...)
 * is one entry plus a renderer class.
 */
class ConfigRevisionDiffRegistry
{
    /** @var array<string, class-string<ConfigRevisionDiffRenderer>> */
    protected array $map = [
        'php_cli_ini' => PhpFileDiffRenderer::class,
        'php_fpm_ini' => PhpFileDiffRenderer::class,
        'php_pool' => PhpFileDiffRenderer::class,
        'webserver_config' => WebserverConfigDiffRenderer::class,
    ];

    public function __construct(protected Container $container) {}

    public function rendererFor(string $kind): ConfigRevisionDiffRenderer
    {
        if (! isset($this->map[$kind])) {
            throw new RuntimeException("No diff renderer registered for kind '{$kind}'.");
        }

        return $this->container->make($this->map[$kind]);
    }

    public function supports(string $kind): bool
    {
        return isset($this->map[$kind]);
    }

    /**
     * @param  class-string<ConfigRevisionDiffRenderer>  $renderer
     */
    public function register(string $kind, string $renderer): void
    {
        $this->map[$kind] = $renderer;
    }
}
