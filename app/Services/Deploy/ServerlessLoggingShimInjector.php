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
 * This injector drops a per-language shim into the repo. The shim wraps the
 * user's own action, fire-and-forget POSTs each organic invocation to dply's
 * ingest endpoint, and returns the user's result unchanged. The user's code
 * is never edited — the deployer simply points the action's OpenWhisk
 * `exec.main` at the shim's entrypoint instead.
 *
 * Two-step shape mirrors {@see DigitalOceanFunctionsLaravelAdapter}:
 * {@see plan()} is pure and testable; {@see inject()} performs the write.
 */
class ServerlessLoggingShimInjector
{
    /** OpenWhisk exec.main symbol each language's shim exposes. */
    private const SHIM_FUNCTION = [
        'node' => 'dplyMain',
        'python' => 'dplyMain',
        'php' => 'dplyMain',
        'go' => 'DplyMain',
    ];

    /** Filename the shim is written as, at the repo root. */
    private const SHIM_FILENAME = [
        'node' => '__dply_shim.js',
        'python' => '__dply_shim.py',
        'php' => '__dply_shim.php',
        'go' => '__dply_shim.go',
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

        // Go shares `package main` with the user's action and calls Main
        // directly, so it needs no entry-file reference. Every other shim
        // loads the repo's entry file by name.
        if ($language !== 'go') {
            if ($entryFile === '') {
                throw new RuntimeException('A raw '.$language.' action needs an entry file for the logging shim to wrap.');
            }
            if (! is_file($workingDirectory.'/'.$entryFile)) {
                throw new RuntimeException('The raw action entry file was not found in the repository: '.$entryFile);
            }
        }

        $contents = str_replace(
            '{{DPLY_ENTRY}}',
            $entryFile,
            (string) file_get_contents($templatePath),
        );

        $shimFile = self::SHIM_FILENAME[$language];
        file_put_contents($workingDirectory.'/'.$shimFile, $contents);

        return [
            'ran' => true,
            'shim_file' => $shimFile,
            'function' => self::SHIM_FUNCTION[$language],
            'output' => 'Injected dply logging shim '.$shimFile.' wrapping '
                .($language === 'go' ? 'the action\'s Main()' : $entryFile).'.',
        ];
    }
}
