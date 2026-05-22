<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use RuntimeException;

/**
 * Injects dply's logging shim into a checked-out raw OpenWhisk action.
 *
 * A framework app gets a dply-injected handler (the Laravel adapter, Bref)
 * that reports organic traffic; a raw action — a bare `main()` — has none,
 * so without help it is invisible to dply (the DigitalOcean Functions
 * activations list API is structurally empty).
 *
 * This injector makes the shim *become* the action's OpenWhisk entry file
 * (`index.js` / `__main__.py` / `index.php` — the filenames the DO Functions
 * runtimes load), wrapping the repo's own action. The shim fire-and-forget
 * POSTs each organic invocation to dply's ingest endpoint and returns the
 * user's result unchanged. The user's code is not rewritten — at most its
 * entry file is renamed out of the way so the shim can take the entry slot.
 * Go is compiled as one package, so its shim is an extra source file and
 * the action's `exec.main` is simply pointed at the shim function.
 *
 * Two-step shape mirrors {@see DigitalOceanFunctionsLaravelAdapter}:
 * {@see plan()} is pure and testable; {@see inject()} performs the writes.
 */
class ServerlessLoggingShimInjector
{
    /** OpenWhisk `exec.main` symbol each language's shim exposes. */
    private const SHIM_FUNCTION = [
        'node' => 'dplyMain',
        'python' => 'dplyMain',
        'php' => 'dplyMain',
        'go' => 'DplyMain',
    ];

    /**
     * The filename the shim is written as. For node/python/php this is the
     * OpenWhisk runtime's entry file, so the shim is what the runtime loads.
     * Go compiles the whole package, so its shim is just an extra file —
     * named without a leading `_`, which the Go build tool would ignore.
     */
    private const SHIM_FILENAME = [
        'node' => 'index.js',
        'python' => '__main__.py',
        'php' => 'index.php',
        'go' => 'dply_shim.go',
    ];

    /** Where the user's entry file is moved if it collides with the shim. */
    private const RENAMED_USER_FILE = [
        'node' => '__dply_action.js',
        'python' => '__dply_action.py',
        'php' => '__dply_action.php',
    ];

    /** Shim template under resources/serverless/shims. */
    private const SHIM_TEMPLATE = [
        'node' => 'raw-node.js',
        'python' => 'raw-python.py',
        'php' => 'raw-php.php',
        'go' => 'raw-go.go',
    ];

    public function supports(string $language): bool
    {
        return isset(self::SHIM_FUNCTION[strtolower(trim($language))]);
    }

    /**
     * Decide what the shim injection would do. Pure — writes nothing.
     *
     * @return array{supported: bool, shim_file: string, function: string, entry_file: string}
     */
    public function plan(string $language, string $entryFile): array
    {
        $language = strtolower(trim($language));

        if (! $this->supports($language)) {
            return ['supported' => false, 'shim_file' => '', 'function' => '', 'entry_file' => trim($entryFile)];
        }

        return [
            'supported' => true,
            'shim_file' => self::SHIM_FILENAME[$language],
            'function' => self::SHIM_FUNCTION[$language],
            'entry_file' => trim($entryFile),
        ];
    }

    /**
     * Write the logging shim into a checked-out raw-action repo. Returns the
     * OpenWhisk `exec.main` the deployer must point the action at.
     *
     * @return array{ran: bool, shim_file: string, function: string, output: string}
     */
    public function inject(string $workingDirectory, string $language, string $entryFile): array
    {
        $language = strtolower(trim($language));
        $entryFile = trim($entryFile);

        if (! $this->supports($language)) {
            return ['ran' => false, 'shim_file' => '', 'function' => '', 'output' => 'No logging shim is available for language "'.$language.'".'];
        }

        $templatePath = resource_path('serverless/shims/'.self::SHIM_TEMPLATE[$language]);
        if (! is_file($templatePath)) {
            throw new RuntimeException('dply logging shim template is missing: '.$templatePath);
        }

        $shimFile = self::SHIM_FILENAME[$language];
        $template = (string) file_get_contents($templatePath);

        // Go shares `package main` with the user's action and calls Main
        // directly — drop the shim in as an extra source file and stop.
        if ($language === 'go') {
            file_put_contents($workingDirectory.'/'.$shimFile, $template);

            return [
                'ran' => true,
                'shim_file' => $shimFile,
                'function' => self::SHIM_FUNCTION[$language],
                'output' => 'Injected dply logging shim '.$shimFile.' wrapping the action\'s Main().',
            ];
        }

        if ($entryFile === '') {
            throw new RuntimeException('A raw '.$language.' action needs an entry file for the logging shim to wrap.');
        }
        if (! is_file($workingDirectory.'/'.$entryFile)) {
            throw new RuntimeException('The raw action entry file was not found in the repository: '.$entryFile);
        }

        // The shim takes over the runtime's entry filename. If the user's own
        // entry file already occupies that name, move it aside so the shim
        // can require it from its new location.
        $requireTarget = $entryFile;
        if ($entryFile === $shimFile) {
            $requireTarget = self::RENAMED_USER_FILE[$language];
            rename($workingDirectory.'/'.$entryFile, $workingDirectory.'/'.$requireTarget);
        }

        file_put_contents(
            $workingDirectory.'/'.$shimFile,
            str_replace('{{DPLY_ENTRY}}', $requireTarget, $template),
        );

        // Node resolves a zip action's entry from package.json `main`; make
        // sure that points at the shim and not the user's original file.
        if ($language === 'node') {
            $this->prepareNodePackageJson($workingDirectory, $shimFile);
        }

        return [
            'ran' => true,
            'shim_file' => $shimFile,
            'function' => self::SHIM_FUNCTION[$language],
            'output' => 'Injected dply logging shim as '.$shimFile.', wrapping '.$requireTarget.'.',
        ];
    }

    /**
     * Prepare package.json so the OpenWhisk Node runtime loads the shim:
     * point `main` at it, and force `type` to `commonjs`.
     *
     * The shim is CommonJS (it uses `require`). A repo-level `"type":
     * "module"` would make node treat the `.js` shim as an ES module and the
     * `require` call would throw at cold start — so the type is normalised
     * here regardless of what the user's repo declared.
     */
    private function prepareNodePackageJson(string $workingDirectory, string $shimFile): void
    {
        $packageJsonPath = $workingDirectory.'/package.json';

        $decoded = is_file($packageJsonPath)
            ? json_decode((string) file_get_contents($packageJsonPath), true)
            : [];
        $decoded = is_array($decoded) ? $decoded : [];

        $decoded['main'] = $shimFile;
        $decoded['type'] = 'commonjs';

        file_put_contents(
            $packageJsonPath,
            (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
