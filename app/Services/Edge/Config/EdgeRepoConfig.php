<?php

declare(strict_types=1);

namespace App\Services\Edge\Config;

/**
 * Normalized snapshot of a repo's dply.yaml / dply.yml / dply.json at
 * build time. Always shaped the same way regardless of source format —
 * downstream code (build runner, worker payload builder, UI) reads off
 * the array form.
 *
 * Note: `bindings` is NOT a dply.yaml field. Binding declarations come
 * exclusively from the repo's `wrangler.toml` (discovered at build time
 * by WranglerBindingsExtractor and merged onto `repo_config['bindings']`
 * by EdgeBuildRunner). Per-site default `env.KV` is provisioned by
 * EnsureDefaultEdgeBindings.
 */
final class EdgeRepoConfig
{
    /**
     * @param  array{command?: string, output?: string, root?: string, node?: string}  $build
     * @param  list<string>  $envFiles  Repo-relative dotenv paths to load before the build runs.
     * @param  list<array{from: string, to: string, status: int}>  $redirects
     * @param  list<array{from: string, to: string}>  $rewrites
     * @param  list<array{for: string, values: array<string, string>}>  $headers
     * @param  list<array{schedule: string, handler?: string}>  $crons
     * @param  array{country_mode?: string, countries?: list<string>}  $firewall
     * @param  array{url?: string, routes?: list<string>, failover_html?: string}  $origin
     * @param  array{allowed_hosts?: list<string>}  $images
     * @param  array{kv?: array<string, string>, r2?: array<string, string>, d1?: array<string, string>, queues?: array<string, string>}  $bindings
     * @param  array{html_404?: string, html_500?: string, html_404_path?: string, html_500_path?: string}  $errorPages
     * @param  array{enabled?: bool, html?: string, html_path?: string}  $maintenance
     * @param  list<string>  $domains
     * @param  array{enabled?: bool, pr_only?: bool, branches?: list<string>, exclude_branches?: list<string>, protection?: array{mode?: string, allowed_emails?: list<string>}}  $previews
     * @param  array{enabled?: bool}  $commentWidget
     * @param  array{public?: array<string, string>, secret?: list<string>}  $env
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly array $build = [],
        public readonly array $envFiles = [],
        public readonly array $redirects = [],
        public readonly array $rewrites = [],
        public readonly array $headers = [],
        public readonly array $crons = [],
        public readonly array $firewall = [],
        public readonly array $origin = [],
        public readonly array $images = [],
        public readonly array $bindings = [],
        public readonly array $errorPages = [],
        public readonly array $maintenance = [],
        public readonly array $domains = [],
        public readonly array $previews = [],
        public readonly array $commentWidget = [],
        public readonly array $env = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @return array{
     *     source_path: string,
     *     build: array<string, string>,
     *     env_files: list<string>,
     *     redirects: list<array{from: string, to: string, status: int}>,
     *     rewrites: list<array{from: string, to: string}>,
     *     headers: list<array{for: string, values: array<string, string>}>,
     *     crons: list<array{schedule: string, handler?: string}>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'build' => $this->build,
            'env_files' => $this->envFiles,
            'redirects' => $this->redirects,
            'rewrites' => $this->rewrites,
            'headers' => $this->headers,
            'crons' => $this->crons,
            'firewall' => $this->firewall,
            'origin' => $this->origin,
            'images' => $this->images,
            'bindings' => $this->bindings,
            'error_pages' => $this->errorPages,
            'maintenance' => $this->maintenance,
            'domains' => $this->domains,
            'previews' => $this->previews,
            'comment_widget' => $this->commentWidget,
            'env' => $this->env,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return list<string>
     */
    public function cronSchedules(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $entry): ?string => is_string($entry['schedule'] ?? null) && $entry['schedule'] !== ''
                ? $entry['schedule']
                : null,
            $this->crons,
        )));
    }

    public function isEmpty(): bool
    {
        return $this->build === []
            && $this->envFiles === []
            && $this->redirects === []
            && $this->rewrites === []
            && $this->headers === []
            && $this->crons === []
            && $this->firewall === []
            && $this->origin === []
            && $this->images === []
            && $this->bindings === []
            && $this->errorPages === []
            && $this->maintenance === []
            && $this->domains === []
            && $this->previews === []
            && $this->commentWidget === []
            && $this->env === [];
    }
}
