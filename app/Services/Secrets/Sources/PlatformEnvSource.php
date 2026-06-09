<?php

declare(strict_types=1);

namespace App\Services\Secrets\Sources;

use App\Services\Secrets\Contracts\SecretSource;
use App\Services\Secrets\Scope;
use RuntimeException;

/**
 * The control-plane `.env` (which carries APP_KEY + all prod secrets). On a box
 * the path resolves to the symlinked release `.env`, i.e. `$ROOT/shared/.env`.
 */
final class PlatformEnvSource implements SecretSource
{
    public function __construct(private readonly string $envPath) {}

    public function name(): string
    {
        return 'platform-env';
    }

    public function gather(Scope $scope): string
    {
        if (! is_file($this->envPath) || ! is_readable($this->envPath)) {
            throw new RuntimeException("env file not readable: {$this->envPath}");
        }

        $contents = file_get_contents($this->envPath);
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException("refusing to escrow an empty env file: {$this->envPath}");
        }

        return $contents;
    }
}
