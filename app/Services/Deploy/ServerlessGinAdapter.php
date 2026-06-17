<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use RuntimeException;

/**
 * Injects the DigitalOcean Functions ↔ Gin adapter into a checked-out Go
 * web app so it deploys as a single OpenWhisk web action.
 *
 * Go is statically compiled, so — unlike the dynamic-language adapters —
 * dply cannot discover the router by introspection. The repo must export
 * `func Router() http.Handler` (a `*gin.Engine` satisfies it). The adapter
 * is added as an extra `package main` source file; the OpenWhisk Go runtime
 * compiles it alongside the repo, and dply points `exec.main` at DplyMain.
 *
 * Two-step shape: {@see plan()} is pure and testable; {@see inject()}
 * performs the write.
 */
class ServerlessGinAdapter
{
    /**
     * Extra Go source file the adapter is added as (shares package main).
     * Must not start with `_` or `.` — the Go build tool ignores such files.
     */
    public const HANDLER_FILENAME = 'dply_adapter.go';

    /** OpenWhisk `exec.main` the deployer must point the action at. */
    public const HANDLER_FUNCTION = 'DplyMain';

    /**
     * Inspect a checked-out Go app. Pure — reads files, writes nothing.
     *
     * @return array{gin: bool, handler: string, function: string, has_router: bool}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        return [
            'gin' => $this->dependsOnGin($workingDirectory),
            'handler' => self::HANDLER_FILENAME,
            'function' => self::HANDLER_FUNCTION,
            'has_router' => $this->declaresRouter($workingDirectory),
        ];
    }

    /**
     * Write the adapter into the checked-out Go app. No-op for non-Gin
     * repos; throws when a Gin repo does not export the required `Router()`.
     *
     * @return array{gin: bool, handler: string, function: string, has_router: bool, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['gin']) {
            return $plan + ['ran' => false, 'output' => ''];
        }

        if (! $plan['has_router']) {
            throw new RuntimeException('dply Gin adapter: the repository must export `func Router() http.Handler` (a *gin.Engine satisfies it) so dply can drive it as an OpenWhisk action.');
        }

        $stub = $this->stubPath();
        if (! is_file($stub)) {
            throw new RuntimeException('DigitalOcean Functions Gin adapter stub is missing: '.$stub);
        }

        $target = rtrim($workingDirectory, '/').'/'.self::HANDLER_FILENAME;
        if (file_put_contents($target, (string) file_get_contents($stub)) === false) {
            throw new RuntimeException('Could not write the Gin adapter to '.$target);
        }

        return $plan + [
            'ran' => true,
            'output' => 'Injected DigitalOcean Functions Gin adapter as '.self::HANDLER_FILENAME.'.',
        ];
    }

    public function stubPath(): string
    {
        return resource_path('serverless/adapters/gin.go');
    }

    private function dependsOnGin(string $workingDirectory): bool
    {
        $goMod = rtrim($workingDirectory, '/').'/go.mod';

        return is_file($goMod)
            && str_contains((string) file_get_contents($goMod), 'github.com/gin-gonic/gin');
    }

    /**
     * True when a repo-root `package main` file exports `func Router(`.
     * OpenWhisk compiles a Go action from the flat set of root `.go` files,
     * so the router export must live there.
     */
    private function declaresRouter(string $workingDirectory): bool
    {
        foreach (glob(rtrim($workingDirectory, '/').'/*.go') ?: [] as $file) {
            if (basename($file) === self::HANDLER_FILENAME) {
                continue;
            }
            if (preg_match('/func\s+Router\s*\(/', (string) file_get_contents($file)) === 1) {
                return true;
            }
        }

        return false;
    }
}
