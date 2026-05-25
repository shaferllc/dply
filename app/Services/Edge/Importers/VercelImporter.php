<?php

declare(strict_types=1);

namespace App\Services\Edge\Importers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Vercel import — uses a Vercel access token (Account Settings →
 * Tokens). Optionally targeted at a specific team via teamId.
 *
 * Pulls project list + the build/env detail for a chosen one. Env
 * decryption requires the token to have read access to encrypted
 * env values; redacted values surface as empty strings in the
 * importer output (the wizard tells the user which keys to re-enter).
 */
class VercelImporter implements EdgeImporter
{
    private const BASE = 'https://api.vercel.com';

    public function __construct(
        private readonly string $apiToken,
        private readonly ?string $teamId = null,
    ) {}

    public function providerKey(): string
    {
        return 'vercel';
    }

    public function providerLabel(): string
    {
        return 'Vercel';
    }

    public function probe(): array
    {
        $response = $this->http()->get(self::BASE.'/v2/user');
        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Vercel rejected the token ('.$response->status().'). Confirm the token is valid and (if you\'re on a team) include the team id.',
            ];
        }

        $data = (array) $response->json();
        $user = is_array($data['user'] ?? null) ? $data['user'] : $data;

        return [
            'ok' => true,
            'message' => 'Authenticated.',
            'principal' => (string) ($user['name'] ?? $user['email'] ?? 'Vercel user'),
        ];
    }

    public function listProjects(): array
    {
        $params = ['limit' => 100];
        if ($this->teamId !== null) {
            $params['teamId'] = $this->teamId;
        }
        $response = $this->http()->get(self::BASE.'/v9/projects', $params);
        if (! $response->successful()) {
            throw new RuntimeException('Vercel projects list failed: '.$response->status());
        }

        $payload = (array) $response->json();
        $projects = is_array($payload['projects'] ?? null) ? $payload['projects'] : [];
        $out = [];
        foreach ($projects as $project) {
            if (! is_array($project)) {
                continue;
            }
            $link = is_array($project['link'] ?? null) ? $project['link'] : [];
            $repo = $this->repoFromLink($link);
            $out[] = [
                'id' => (string) ($project['id'] ?? ''),
                'name' => (string) ($project['name'] ?? 'untitled'),
                'repo' => $repo,
                'framework' => is_string($project['framework'] ?? null) ? $project['framework'] : null,
                'live_url' => $this->liveUrlFromProject($project),
                'updated_at' => is_int($project['updatedAt'] ?? null)
                    ? gmdate('c', (int) ($project['updatedAt'] / 1000))
                    : null,
            ];
        }

        return array_values(array_filter($out, fn (array $row): bool => $row['id'] !== ''));
    }

    public function fetchProject(string $projectId): ImportedEdgeProject
    {
        $params = $this->teamId !== null ? ['teamId' => $this->teamId] : [];

        $response = $this->http()->get(self::BASE.'/v9/projects/'.rawurlencode($projectId), $params);
        if (! $response->successful()) {
            throw new RuntimeException('Vercel project '.$projectId.' fetch failed: '.$response->status());
        }
        $project = (array) $response->json();

        $envParams = array_merge($params, ['decrypt' => 'true']);
        $envResponse = $this->http()->get(self::BASE.'/v9/projects/'.rawurlencode($projectId).'/env', $envParams);
        $envVars = [];
        if ($envResponse->successful()) {
            $envPayload = (array) $envResponse->json();
            $entries = is_array($envPayload['envs'] ?? null) ? $envPayload['envs'] : [];
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $targets = is_array($entry['target'] ?? null) ? $entry['target'] : [];
                if (! in_array('production', $targets, true)) {
                    continue;
                }
                $key = (string) ($entry['key'] ?? '');
                $value = (string) ($entry['value'] ?? '');
                if ($key === '' || $value === '') {
                    continue;
                }
                $envVars[$key] = $value;
            }
        }

        $domainsResponse = $this->http()->get(
            self::BASE.'/v9/projects/'.rawurlencode($projectId).'/domains',
            $params,
        );
        $customDomains = [];
        if ($domainsResponse->successful()) {
            $domains = (array) $domainsResponse->json();
            $entries = is_array($domains['domains'] ?? null) ? $domains['domains'] : [];
            foreach ($entries as $domain) {
                if (is_array($domain) && is_string($domain['name'] ?? null)) {
                    $customDomains[] = strtolower($domain['name']);
                }
            }
        }

        $link = is_array($project['link'] ?? null) ? $project['link'] : [];

        return new ImportedEdgeProject(
            sourceProvider: 'vercel',
            sourceProjectId: $projectId,
            name: (string) ($project['name'] ?? 'imported-from-vercel'),
            repoUrl: $this->repoFromLink($link),
            branch: is_string($link['productionBranch'] ?? null) ? $link['productionBranch'] : null,
            framework: is_string($project['framework'] ?? null) ? $project['framework'] : null,
            buildCommand: (string) ($project['buildCommand'] ?? ''),
            outputDir: (string) ($project['outputDirectory'] ?? ''),
            runtimeMode: $this->runtimeModeFromFramework((string) ($project['framework'] ?? '')),
            envVars: $envVars,
            customDomains: array_values(array_unique($customDomains)),
            sourceLiveUrl: $this->liveUrlFromProject($project),
            sourceDashboardUrl: 'https://vercel.com/dashboard',
        );
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function repoFromLink(array $link): ?string
    {
        $type = strtolower((string) ($link['type'] ?? ''));
        if ($type === 'github') {
            $org = (string) ($link['org'] ?? '');
            $repo = (string) ($link['repo'] ?? '');
            if ($org !== '' && $repo !== '') {
                return $org.'/'.$repo;
            }
        }
        $url = $link['repoUrl'] ?? null;
        if (is_string($url) && $url !== '') {
            return preg_replace('#^https?://github\.com/#i', '', rtrim($url, '/')) ?: $url;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function liveUrlFromProject(array $project): ?string
    {
        $alias = is_array($project['alias'] ?? null) ? $project['alias'] : [];
        foreach ($alias as $entry) {
            if (is_array($entry) && is_string($entry['domain'] ?? null)) {
                return 'https://'.$entry['domain'];
            }
        }
        $targets = is_array($project['targets'] ?? null) ? $project['targets'] : [];
        $production = $targets['production'] ?? null;
        if (is_array($production) && is_string($production['url'] ?? null)) {
            return 'https://'.$production['url'];
        }

        return null;
    }

    private function runtimeModeFromFramework(string $framework): string
    {
        // Vercel's heaviest hitters are SSR — pre-pick SSR mode when
        // we recognize them so the wizard surfaces the right option.
        return match (strtolower($framework)) {
            'nextjs', 'next', 'remix', 'sveltekit', 'nuxtjs', 'nuxt' => 'ssr',
            default => 'static',
        };
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson();
    }
}
