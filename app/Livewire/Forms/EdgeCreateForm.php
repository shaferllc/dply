<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Form;

class EdgeCreateForm extends Form
{
    public const DEFAULT_BUILD_COMMAND = 'npm ci && npm run build';

    public const DEFAULT_OUTPUT_DIR = 'dist';

    public string $name = '';

    public string $build_command = '';

    public string $output_dir = '';

    public bool $spa_fallback = true;

    public bool $deploy_on_push = true;

    public string $runtime_mode = 'static';

    public string $origin_url = '';

    public string $origin_cloud_site_id = '';

    /** managed = dply platform; byo = org Cloudflare credential */
    public string $delivery_mode = 'managed';

    public string $edge_provider_credential_id = '';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'build_command' => ['nullable', 'string', 'max:500'],
            'output_dir' => ['nullable', 'string', 'max:200'],
            'spa_fallback' => ['boolean'],
            'deploy_on_push' => ['boolean'],
            'runtime_mode' => ['required', 'in:static,hybrid'],
            'origin_url' => ['nullable', 'string', 'max:500'],
            'delivery_mode' => ['required', 'in:managed,byo'],
            'edge_provider_credential_id' => ['required_if:delivery_mode,byo', 'nullable', 'string'],
        ];
    }

    public function resolvedBuildCommand(): string
    {
        $buildCommand = trim($this->build_command);

        return $buildCommand !== '' ? $buildCommand : self::DEFAULT_BUILD_COMMAND;
    }

    public function resolvedOutputDir(): string
    {
        $outputDir = trim($this->output_dir);

        return $outputDir !== '' ? $outputDir : self::DEFAULT_OUTPUT_DIR;
    }

    /**
     * @return array<string, mixed>
     */
    public function createEdgeSitePayload(string $framework, string $repo, string $branch): array
    {
        return [
            'name' => $this->name,
            'repo' => $repo,
            'branch' => $branch,
            'build_command' => $this->resolvedBuildCommand(),
            'output_dir' => $this->resolvedOutputDir(),
            'spa_fallback' => $this->spa_fallback,
            'deploy_on_push' => $this->deploy_on_push,
            'framework' => $framework,
            'runtime_mode' => $this->runtime_mode,
            'origin_url' => trim($this->origin_url),
            'cloud_site_id' => $this->origin_cloud_site_id !== '' ? $this->origin_cloud_site_id : null,
            'origin_routes' => ['/_next/*', '/api/*'],
            'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
            'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $detectedPlan
     * @return array<string, mixed>
     */
    public function hybridStackPayload(array $detectedPlan, string $repo, string $branch): array
    {
        return [
            'name' => $this->name,
            'repo' => $repo,
            'branch' => $branch,
            'build_command' => $this->resolvedBuildCommand(),
            'output_dir' => $this->resolvedOutputDir(),
            'spa_fallback' => $this->spa_fallback,
            'deploy_on_push' => $this->deploy_on_push,
            'detected_plan' => $detectedPlan,
            'origin_routes' => ['/_next/*', '/api/*'],
            'edge_backend' => $this->delivery_mode === 'byo' ? 'org_cloudflare' : 'dply_edge',
            'edge_provider_credential_id' => $this->delivery_mode === 'byo' ? $this->edge_provider_credential_id : null,
        ];
    }

    public static function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
