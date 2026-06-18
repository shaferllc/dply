<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use RuntimeException;

/**
 * Injects the DigitalOcean Functions ↔ Laravel adapter into a checked-out
 * Laravel app so it can run on DO Functions' native PHP runtime — the
 * OpenWhisk counterpart to {@see BrefInjector} (which targets AWS Lambda).
 *
 * DigitalOcean Functions has no Laravel bridge of its own: it invokes a
 * plain `main($args)` web action. This adapter writes an `index.php` entry
 * file at the repo root that translates that event into a Laravel HTTP
 * request and back. The user's repo carries no serverless boilerplate — the
 * glue is added at build time and overwritten on every deploy.
 *
 * Two-step shape mirrors BrefInjector: {@see plan()} is pure (reads
 * composer.json / project markers) and fully testable; {@see inject()}
 * performs the side effect (writes the handler into the working tree).
 */
class DigitalOceanFunctionsLaravelAdapter
{
    /** Repo-root entry file the OpenWhisk PHP runtime loads from the zip. */
    public const HANDLER_FILENAME = 'index.php';

    /** OpenWhisk function name the deployer must set as `exec.main`. */
    public const HANDLER_FUNCTION = 'main';

    /**
     * Inspect a checked-out app and decide whether the Laravel adapter
     * applies. Pure — reads files, writes nothing.
     *
     * @return array{laravel: bool, handler: string, function: string}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        return [
            'laravel' => $this->looksLikeLaravel($workingDirectory),
            'handler' => self::HANDLER_FILENAME,
            'function' => self::HANDLER_FUNCTION,
        ];
    }

    /**
     * Write the adapter `index.php` into the checked-out Laravel app so the
     * built zip carries the OpenWhisk runtime glue. No-op for non-Laravel
     * repos.
     *
     * @return array{laravel: bool, handler: string, function: string, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['laravel']) {
            return $plan + ['ran' => false, 'output' => ''];
        }

        $stub = $this->stubPath();
        if (! is_file($stub)) {
            throw new RuntimeException('DigitalOcean Functions Laravel handler stub is missing: '.$stub);
        }

        $target = rtrim($workingDirectory, '/').'/'.self::HANDLER_FILENAME;
        if (file_put_contents($target, (string) file_get_contents($stub)) === false) {
            throw new RuntimeException('Could not write the DigitalOcean Functions Laravel adapter to '.$target);
        }

        return $plan + [
            'ran' => true,
            'output' => 'Injected DigitalOcean Functions Laravel adapter at '.self::HANDLER_FILENAME.'.',
        ];
    }

    /**
     * Mirrors ServerlessRuntimeDetector::looksLikeLaravel — artisan +
     * bootstrap/app.php, the routes/public pair, or laravel/framework in
     * composer.json.
     */
    private function looksLikeLaravel(string $workingDirectory): bool
    {
        $dir = rtrim($workingDirectory, '/');

        if (is_file($dir.'/artisan') && is_file($dir.'/bootstrap/app.php')) {
            return true;
        }

        if (is_file($dir.'/routes/web.php') && is_file($dir.'/public/index.php')) {
            return true;
        }

        $composer = $dir.'/composer.json';
        if (! is_file($composer)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($composer), true);
        $require = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];

        return array_key_exists('laravel/framework', $require);
    }

    public function stubPath(): string
    {
        return resource_path('serverless/digitalocean-functions-laravel-handler.php');
    }
}
