<?php

declare(strict_types=1);

namespace App\Services\Edge\Importers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Netlify import — uses a personal access token (Settings → User
 * settings → Applications → Personal access tokens in Netlify). Reads
 * the team's sites, then pulls build config + env vars + custom
 * domains for the picked one.
 *
 * The translation favours fidelity over completeness — we don't try
 * to replicate every Netlify-specific feature (functions, edge
 * functions, plugins). What we extract:
 *
 *   - Repo URL + branch                  → Edge Create form
 *   - build_command / publish_dir        → build settings
 *   - Environment variables (build-time) → env_vars (plaintext)
 *   - Custom domains                     → custom_domains list
 *   - _redirects file (when fetched separately by the wizard) — not pulled here
 *
 * Anything else (Netlify Functions, Forms, Identity) is documented
 * as "won't migrate" in the wizard summary so users aren't surprised.
 */
class NetlifyImporter implements EdgeImporter
{
    private const BASE = 'https://api.netlify.com/api/v1';

    public function __construct(
        private readonly string $apiToken,
    ) {}

    public function providerKey(): string
    {
        return 'netlify';
    }

    public function providerLabel(): string
    {
        return 'Netlify';
    }

    public function probe(): array
    {
        $response = $this->http()->get(self::BASE.'/user');
        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Netlify rejected the token ('.$response->status().'). Check it has access to your team.',
            ];
        }

        $data = (array) $response->json();

        return [
            'ok' => true,
            'message' => 'Authenticated.',
            'principal' => (string) ($data['full_name'] ?? $data['email'] ?? 'Netlify user'),
        ];
    }

    public function listProjects(): array
    {
        $response = $this->http()->get(self::BASE.'/sites', [
            'per_page' => 100,
            'sort_by' => 'updated_at',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Netlify sites list failed: '.$response->status());
        }

        $sites = (array) $response->json();
        $out = [];
        foreach ($sites as $site) {
            if (! is_array($site)) {
                continue;
            }
            $repo = $this->repoFromSite($site);
            $framework = $this->frameworkFromSite($site);
            $out[] = [
                'id' => (string) ($site['id'] ?? $site['site_id'] ?? ''),
                'name' => (string) ($site['name'] ?? $site['custom_domain'] ?? 'untitled'),
                'repo' => $repo,
                'framework' => $framework,
                'live_url' => is_string($site['ssl_url'] ?? null)
                    ? $site['ssl_url']
                    : (is_string($site['url'] ?? null) ? $site['url'] : null),
                'updated_at' => is_string($site['updated_at'] ?? null) ? $site['updated_at'] : null,
            ];
        }

        return array_values(array_filter($out, fn (array $row): bool => $row['id'] !== ''));
    }

    public function fetchProject(string $projectId): ImportedEdgeProject
    {
        $site = $this->http()->get(self::BASE.'/sites/'.rawurlencode($projectId))->json();
        if (! is_array($site)) {
            throw new RuntimeException('Netlify site '.$projectId.' could not be fetched.');
        }

        $envVars = $this->fetchEnvVars($site);
        $customDomains = $this->customDomainsFromSite($site);
        $repo = $this->repoFromSite($site);
        $build = (array) ($site['build_settings'] ?? []);

        return new ImportedEdgeProject(
            sourceProvider: 'netlify',
            sourceProjectId: $projectId,
            name: (string) ($site['name'] ?? 'imported-from-netlify'),
            repoUrl: $repo,
            branch: $this->branchFromBuildSettings($build),
            framework: $this->frameworkFromSite($site),
            buildCommand: (string) ($build['cmd'] ?? ''),
            outputDir: (string) ($build['dir'] ?? ''),
            runtimeMode: 'static',
            envVars: $envVars,
            customDomains: $customDomains,
            sourceLiveUrl: is_string($site['ssl_url'] ?? null) ? $site['ssl_url'] : null,
            sourceDashboardUrl: is_string($site['admin_url'] ?? null) ? $site['admin_url'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, string>
     */
    private function fetchEnvVars(array $site): array
    {
        $accountSlug = is_string($site['account_slug'] ?? null) ? $site['account_slug'] : '';
        $siteId = (string) ($site['id'] ?? '');
        if ($accountSlug === '' || $siteId === '') {
            return [];
        }

        // Netlify's newer accounts/{slug}/env API supersedes the
        // legacy build_settings.env. Try it first; on 404 fall back.
        $response = $this->http()->get(self::BASE.'/accounts/'.rawurlencode($accountSlug).'/env', [
            'site_id' => $siteId,
        ]);

        if ($response->successful()) {
            $out = [];
            foreach ((array) $response->json() as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key = (string) ($entry['key'] ?? '');
                $values = is_array($entry['values'] ?? null) ? $entry['values'] : [];
                if ($key === '') {
                    continue;
                }
                // Pick the production-context value first, fall back
                // to any non-empty value so previews still get tokens.
                foreach ($values as $value) {
                    if (! is_array($value)) {
                        continue;
                    }
                    $context = strtolower((string) ($value['context'] ?? ''));
                    if ($context !== 'production') {
                        continue;
                    }
                    $out[$key] = (string) ($value['value'] ?? '');

                    continue 2;
                }
                foreach ($values as $value) {
                    if (is_array($value) && is_string($value['value'] ?? null)) {
                        $out[$key] = (string) $value['value'];
                        break;
                    }
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        $legacy = is_array($site['build_settings']['env'] ?? null) ? $site['build_settings']['env'] : [];

        return array_filter(
            array_combine(
                array_keys($legacy),
                array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $legacy),
            ),
            static fn ($v) => $v !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $site
     * @return list<string>
     */
    private function customDomainsFromSite(array $site): array
    {
        $domains = [];
        if (is_string($site['custom_domain'] ?? null) && $site['custom_domain'] !== '') {
            $domains[] = strtolower($site['custom_domain']);
        }
        $aliases = is_array($site['domain_aliases'] ?? null) ? $site['domain_aliases'] : [];
        foreach ($aliases as $alias) {
            if (is_string($alias) && $alias !== '') {
                $domains[] = strtolower($alias);
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function repoFromSite(array $site): ?string
    {
        $repo = $site['build_settings']['repo_url'] ?? null;
        if (is_string($repo) && $repo !== '') {
            return $this->normalizeRepoUrl($repo);
        }
        $alt = $site['repo_url'] ?? null;
        if (is_string($alt) && $alt !== '') {
            return $this->normalizeRepoUrl($alt);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function frameworkFromSite(array $site): ?string
    {
        $hints = [
            (string) ($site['published_deploy']['framework'] ?? ''),
            (string) ($site['build_settings']['framework'] ?? ''),
            (string) ($site['plugin_state']['framework'] ?? ''),
        ];
        foreach ($hints as $hint) {
            if ($hint !== '') {
                return strtolower($hint);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $build
     */
    private function branchFromBuildSettings(array $build): ?string
    {
        $branch = $build['repo_branch'] ?? $build['branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    private function normalizeRepoUrl(string $url): string
    {
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $url, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^git@github\.com:([^/]+/[^/]+?)(?:\.git)?$#i', $url, $m) === 1) {
            return $m[1];
        }

        return $url;
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson();
    }
}
