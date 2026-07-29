<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use RuntimeException;

/**
 * Injects the DigitalOcean Functions ↔ Express adapter into a checked-out
 * Express app so it deploys as a single OpenWhisk web action.
 *
 * The OpenWhisk-side counterpart to {@see DigitalOceanFunctionsLaravelAdapter}
 * for Node: the adapter handler becomes the action's `index.js` entry (the
 * user's own entry file is moved aside and required from there), and
 * `serverless-http` is added to package.json so the build's `npm install`
 * pulls the request/response bridge the adapter relies on.
 *
 * Two-step shape: {@see plan()} is pure and testable; {@see inject()}
 * performs the writes.
 */
class ServerlessExpressAdapter
{
    /** OpenWhisk Node entry file the adapter is written as. */
    public const HANDLER_FILENAME = 'index.js';

    /** OpenWhisk `exec.main` the deployer must point the action at. */
    public const HANDLER_FUNCTION = 'dplyMain';

    /** Where the user's entry file is moved if it collides with the adapter. */
    private const RENAMED_USER_FILE = '__dply_express_app.js';

    /** serverless-http version constraint added to package.json. */
    private const SERVERLESS_HTTP_CONSTRAINT = '^3.2.0';

    /**
     * Inspect a checked-out app and decide whether the Express adapter
     * applies. Pure — reads files, writes nothing.
     *
     * @return array{express: bool, handler: string, function: string, entry: string}
     */
    /** @return array<string, mixed> */
    public function plan(string $workingDirectory): array
    {
        $packageJson = $this->readPackageJson($workingDirectory);

        return [
            'express' => $this->dependsOnExpress($packageJson),
            'handler' => self::HANDLER_FILENAME,
            'function' => self::HANDLER_FUNCTION,
            'entry' => $this->userEntryFile($packageJson),
        ];
    }

    /**
     * Write the adapter into the checked-out Express app. No-op for repos
     * that do not depend on Express.
     *
     * @return array{express: bool, handler: string, function: string, entry: string, ran: bool, output: string}
     */
    /** @return array<string, mixed> */
    public function inject(string $workingDirectory): array
    {
        $plan = $this->plan($workingDirectory);

        if (! $plan['express']) {
            return $plan + ['ran' => false, 'output' => ''];
        }

        $stub = $this->stubPath();
        if (! is_file($stub)) {
            throw new RuntimeException('DigitalOcean Functions Express adapter stub is missing: '.$stub);
        }

        $dir = rtrim($workingDirectory, '/');
        $userEntry = $plan['entry'];

        // The adapter takes the index.js entry slot. If the user's own entry
        // file already occupies that name, move it aside so the adapter can
        // require it from its new location.
        $requireTarget = $userEntry;
        if ($userEntry === self::HANDLER_FILENAME) {
            $requireTarget = self::RENAMED_USER_FILE;
            if (is_file($dir.'/'.$userEntry)) {
                rename($dir.'/'.$userEntry, $dir.'/'.$requireTarget);
            }
        }

        $handler = str_replace(
            '{{DPLY_ENTRY}}',
            $requireTarget,
            (string) file_get_contents($stub),
        );
        if (file_put_contents($dir.'/'.self::HANDLER_FILENAME, $handler) === false) {
            throw new RuntimeException('Could not write the Express adapter to '.$dir.'/'.self::HANDLER_FILENAME);
        }

        $this->preparePackageJson($dir);

        return $plan + [
            'ran' => true,
            'output' => 'Injected DigitalOcean Functions Express adapter as '.self::HANDLER_FILENAME.', wrapping '.$requireTarget.'.',
        ];
    }

    public function stubPath(): string
    {
        return resource_path('serverless/adapters/express.js');
    }

    /**
     * Add `serverless-http` to the dependencies and point `main` at the
     * injected adapter so the OpenWhisk Node runtime loads it.
     */
    private function preparePackageJson(string $workingDirectory): void
    {
        $path = $workingDirectory.'/package.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $decoded = is_array($decoded) ? $decoded : [];

        $dependencies = is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [];
        if (! array_key_exists('serverless-http', $dependencies)) {
            $dependencies['serverless-http'] = self::SERVERLESS_HTTP_CONSTRAINT;
        }
        $decoded['dependencies'] = $dependencies;
        $decoded['main'] = self::HANDLER_FILENAME;

        file_put_contents(
            $path,
            (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param  array<string, mixed>|null  $packageJson
     */
    private function dependsOnExpress(?array $packageJson): bool
    {
        if ($packageJson === null) {
            return false;
        }

        foreach (['dependencies', 'devDependencies'] as $section) {
            $deps = is_array($packageJson[$section] ?? null) ? $packageJson[$section] : [];
            if (array_key_exists('express', $deps)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user's Express entry file — package.json `main`, or Node's
     * `index.js` default.
     *
     * @param  array<string, mixed>|null  $packageJson
     */
    private function userEntryFile(?array $packageJson): string
    {
        $main = is_array($packageJson) ? trim((string) ($packageJson['main'] ?? '')) : '';

        return $main !== '' ? $main : 'index.js';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPackageJson(string $workingDirectory): ?array
    {
        $path = rtrim($workingDirectory, '/').'/package.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
