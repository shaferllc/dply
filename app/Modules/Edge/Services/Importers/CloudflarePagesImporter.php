<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Importers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare Pages import — uses a Cloudflare API token (Account →
 * API Tokens) with at least Pages:Edit. Reads the account's Pages
 * projects, then pulls build + env detail for a picked one.
 *
 * Pages projects already live on Cloudflare, so the migration is
 * usually about consolidating onto a single Edge surface (same R2
 * bucket / KV namespace as the rest of the user's Edge sites) rather
 * than crossing provider boundaries. DNS swap is unnecessary — the
 * hostname stays on Cloudflare regardless.
 */
class CloudflarePagesImporter implements EdgeImporter
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public function providerKey(): string
    {
        return 'cloudflare_pages';
    }

    public function providerLabel(): string
    {
        return 'Cloudflare Pages';
    }

    public function probe(): array
    {
        $response = $this->http()->get(self::BASE.'/accounts/'.$this->accountId);
        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Cloudflare rejected the credential ('.$response->status().'). Confirm the token has Pages:Read for account '.$this->accountId.'.',
            ];
        }

        $body = (array) $response->json();
        $name = is_string($body['result']['name'] ?? null) ? $body['result']['name'] : 'Cloudflare account';

        return ['ok' => true, 'message' => 'Authenticated.', 'principal' => $name.' ('.$this->accountId.')'];
    }

    public function listProjects(): array
    {
        $response = $this->http()->get(self::BASE.'/accounts/'.$this->accountId.'/pages/projects', [
            'per_page' => 100,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare Pages list failed: '.$response->status());
        }

        $payload = (array) $response->json();
        $projects = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $out = [];
        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }
            $source = is_array($project['source'] ?? null) ? $project['source'] : [];
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $repo = isset($config['owner'], $config['repo_name'])
                ? $config['owner'].'/'.$config['repo_name']
                : null;

            $out[] = [
                'id' => (string) ($project['name'] ?? ''),
                'name' => (string) ($project['name'] ?? 'untitled'),
                'repo' => $repo,
                'framework' => is_string($project['build_config']['build_framework'] ?? null)
                    ? $project['build_config']['build_framework']
                    : null,
                'live_url' => 'https://'.($project['subdomain'] ?? $project['name'] ?? '').'.pages.dev',
                'updated_at' => is_string($project['latest_deployment']['modified_on'] ?? null)
                    ? $project['latest_deployment']['modified_on']
                    : null,
            ];
        }

        return array_values(array_filter($out, fn (array $row): bool => $row['id'] !== ''));
    }

    public function fetchProject(string $projectId): ImportedEdgeProject
    {
        $response = $this->http()->get(
            self::BASE.'/accounts/'.$this->accountId.'/pages/projects/'.rawurlencode($projectId),
        );
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare Pages '.$projectId.' fetch failed: '.$response->status());
        }
        $payload = (array) $response->json();
        $project = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        $source = is_array($project['source'] ?? null) ? $project['source'] : [];
        $sourceConfig = is_array($source['config'] ?? null) ? $source['config'] : [];
        $build = is_array($project['build_config'] ?? null) ? $project['build_config'] : [];
        $deploymentConfigs = is_array($project['deployment_configs'] ?? null) ? $project['deployment_configs'] : [];
        $production = is_array($deploymentConfigs['production'] ?? null) ? $deploymentConfigs['production'] : [];

        $envVars = [];
        $envEntries = is_array($production['env_vars'] ?? null) ? $production['env_vars'] : [];
        foreach ($envEntries as $key => $entry) {
            if (! is_array($entry) || ! is_string($key)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'secret_text') {
                // Secrets aren't returned in plaintext by CF; tell the
                // wizard to re-prompt by leaving an empty value.
                $envVars[$key] = '';

                continue;
            }
            $envVars[$key] = (string) ($entry['value'] ?? '');
        }

        $customDomains = [];
        $domainsResponse = $this->http()->get(
            self::BASE.'/accounts/'.$this->accountId.'/pages/projects/'.rawurlencode($projectId).'/domains',
        );
        if ($domainsResponse->successful()) {
            $domainsPayload = (array) $domainsResponse->json();
            $domains = is_array($domainsPayload['result'] ?? null) ? $domainsPayload['result'] : [];
            foreach ($domains as $domain) {
                if (is_array($domain) && is_string($domain['name'] ?? null)) {
                    $customDomains[] = strtolower($domain['name']);
                }
            }
        }

        $repo = isset($sourceConfig['owner'], $sourceConfig['repo_name'])
            ? $sourceConfig['owner'].'/'.$sourceConfig['repo_name']
            : null;

        return new ImportedEdgeProject(
            sourceProvider: 'cloudflare_pages',
            sourceProjectId: $projectId,
            name: (string) ($project['name'] ?? 'imported-from-pages'),
            repoUrl: $repo,
            branch: is_string($sourceConfig['production_branch'] ?? null) ? $sourceConfig['production_branch'] : 'main',
            framework: is_string($build['build_framework'] ?? null) ? $build['build_framework'] : null,
            buildCommand: (string) ($build['build_command'] ?? ''),
            outputDir: (string) ($build['destination_dir'] ?? ''),
            runtimeMode: 'static',
            envVars: $envVars,
            customDomains: array_values(array_unique($customDomains)),
            sourceLiveUrl: 'https://'.($project['subdomain'] ?? $projectId).'.pages.dev',
            sourceDashboardUrl: 'https://dash.cloudflare.com/'.$this->accountId.'/pages/view/'.rawurlencode($projectId),
        );
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson();
    }
}
