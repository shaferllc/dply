<?php

declare(strict_types=1);

namespace App\Services\Edge\Config;

/**
 * Normalized snapshot of a repo's dply.yaml / dply.yml / dply.json at
 * build time. Always shaped the same way regardless of source format —
 * downstream code (build runner, worker payload builder, UI) reads off
 * the array form.
 */
final class EdgeRepoConfig
{
    /**
     * @param  array{command?: string, output?: string, root?: string, node?: string}  $build
     * @param  list<array{from: string, to: string, status: int}>  $redirects
     * @param  list<array{from: string, to: string}>  $rewrites
     * @param  list<array{for: string, values: array<string, string>}>  $headers
     * @param  array{kv?: array<string, string>, r2?: array<string, string>, d1?: array<string, string>, queues?: array<string, string>}  $bindings
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly array $build = [],
        public readonly array $redirects = [],
        public readonly array $rewrites = [],
        public readonly array $headers = [],
        public readonly array $bindings = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @return array{
     *     source_path: string,
     *     build: array<string, string>,
     *     redirects: list<array{from: string, to: string, status: int}>,
     *     rewrites: list<array{from: string, to: string}>,
     *     headers: list<array{for: string, values: array<string, string>}>,
     *     bindings: array<string, array<string, string>>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'build' => $this->build,
            'redirects' => $this->redirects,
            'rewrites' => $this->rewrites,
            'headers' => $this->headers,
            'bindings' => $this->bindings,
            'warnings' => $this->warnings,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->build === []
            && $this->redirects === []
            && $this->rewrites === []
            && $this->headers === []
            && $this->bindings === [];
    }
}
