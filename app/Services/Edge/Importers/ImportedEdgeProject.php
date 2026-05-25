<?php

declare(strict_types=1);

namespace App\Services\Edge\Importers;

/**
 * Normalized representation of a single project pulled from an
 * external Edge host (Vercel, Netlify, Cloudflare Pages). Every
 * importer returns this shape; the import wizard then either pre-fills
 * the Edge Create form or auto-creates the site.
 */
final class ImportedEdgeProject
{
    /**
     * @param  array<string, string>  $envVars  Plain text env vars to copy onto the site.
     * @param  list<array{from: string, to: string, status: int}>  $redirects
     * @param  list<array{for: string, values: array<string, string>}>  $headers
     * @param  list<string>  $customDomains
     */
    public function __construct(
        public readonly string $sourceProvider,
        public readonly string $sourceProjectId,
        public readonly string $name,
        public readonly ?string $repoUrl,
        public readonly ?string $branch,
        public readonly ?string $framework,
        public readonly string $buildCommand,
        public readonly string $outputDir,
        public readonly string $runtimeMode = 'static',
        public readonly array $envVars = [],
        public readonly array $redirects = [],
        public readonly array $headers = [],
        public readonly array $customDomains = [],
        public readonly ?string $sourceLiveUrl = null,
        public readonly ?string $sourceDashboardUrl = null,
    ) {}

    /**
     * Payload shape the Edge Create form (`EdgeCreateForm::deploy()`)
     * consumes via query-string prefill — the import wizard hands
     * this to `redirect()->route('edge.create', $payload)`.
     *
     * @return array<string, string>
     */
    public function toCreateFormPrefill(): array
    {
        $prefill = array_filter([
            'name' => $this->name,
            'repo' => $this->repoUrl,
            'branch' => $this->branch,
            'build_command' => $this->buildCommand,
            'output_dir' => $this->outputDir,
            'runtime_mode' => $this->runtimeMode,
            'framework' => $this->framework,
            'imported_from' => $this->sourceProvider,
            'imported_id' => $this->sourceProjectId,
        ], static fn ($value) => is_string($value) && $value !== '');

        // Defensive — limit URL length to avoid blowing past
        // typical reverse-proxy query-string caps.
        return array_map(static fn ($value) => substr((string) $value, 0, 1024), $prefill);
    }
}
