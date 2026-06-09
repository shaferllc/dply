<?php

declare(strict_types=1);

namespace App\Services\Edge\Config;

/**
 * Validates a parsed {@see EdgeRepoConfig} snapshot. Used by the build
 * runner (fail deploy on fatal parse errors), the public lint API, and
 * `dply edge lint`.
 */
class EdgeRepoConfigLinter
{
    public function __construct(
        private readonly EdgeRepoConfigLoader $loader,
    ) {}

    public function lintDirectory(string $checkoutPath): array
    {
        return $this->lint($this->loader->loadFromDirectory($checkoutPath));
    }

    public function lintContent(string $sourcePath, string $raw): array
    {
        return $this->lint($this->loader->parse($sourcePath, $raw));
    }

    public function lint(?EdgeRepoConfig $config): array
    {
        if ($config === null) {
            return $this->emptyResult();
        }

        $errors = [];
        $warnings = [];

        foreach ($config->warnings as $message) {
            if ($this->isFatal($message)) {
                $errors[] = $message;
            } else {
                $warnings[] = $message;
            }
        }

        return [
            'ok' => $errors === [],
            'source_path' => $config->sourcePath,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'redirects' => count($config->redirects),
                'rewrites' => count($config->rewrites),
                'headers' => count($config->headers),
                'build_keys' => array_keys($config->build),
            ],
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     source_path: null,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     summary: array{redirects: int, rewrites: int, headers: int, build_keys: list<string>}
     * }
     */
    private function emptyResult(): array
    {
        return [
            'ok' => true,
            'source_path' => null,
            'errors' => [],
            'warnings' => [],
            'summary' => [
                'redirects' => 0,
                'rewrites' => 0,
                'headers' => 0,
                'build_keys' => [],
            ],
        ];
    }

    private function isFatal(string $message): bool
    {
        return str_contains($message, 'parse error')
            || str_contains($message, 'could not be parsed')
            || str_contains($message, 'exceeds the');
    }
}
