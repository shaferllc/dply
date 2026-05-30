<?php

declare(strict_types=1);

namespace App\Services\Servers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cached mirror of Caddy's public module index ({@see https://caddyserver.com/docs/modules}).
 */
class CaddyModuleRegistry
{
    /**
     * @return array<string, list<array{name: string, docs: string, package: string, repo: string}>>
     */
    public function moduleIndex(): array
    {
        $ttl = (int) config('caddy_modules.registry_cache_seconds', 86_400);

        return Cache::remember('caddy.module_registry.index', max(300, $ttl), function (): array {
            $url = (string) config('caddy_modules.registry_url', 'https://caddyserver.com/api/modules');
            $response = Http::timeout(20)->acceptJson()->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException(__('Could not load the Caddy module registry (:status).', [
                    'status' => $response->status(),
                ]));
            }

            $payload = $response->json();
            $result = is_array($payload) ? ($payload['result'] ?? null) : null;

            if (! is_array($result)) {
                throw new \RuntimeException(__('The Caddy module registry returned an unexpected response.'));
            }

            return $result;
        });
    }

    /**
     * @return list<array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     * }>
     */
    public function communityPackages(): array
    {
        $byPackage = [];

        foreach ($this->moduleIndex() as $moduleId => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $package = trim((string) ($entry['package'] ?? ''));
                if ($package === '' || $this->isStandardPackage($package)) {
                    continue;
                }

                if (! isset($byPackage[$package])) {
                    $byPackage[$package] = [
                        'path' => $package,
                        'repo' => trim((string) ($entry['repo'] ?? '')),
                        'label' => $this->labelForPackage($package, (string) ($this->catalogEntry($package)['label'] ?? '')),
                        'description' => $this->summarizeDocs((string) ($entry['docs'] ?? '')),
                        'module_ids' => [],
                    ];
                }

                $byPackage[$package]['module_ids'][] = (string) $moduleId;

                $catalogDescription = (string) ($this->catalogEntry($package)['description'] ?? '');
                if ($catalogDescription !== '') {
                    $byPackage[$package]['description'] = $catalogDescription;
                } elseif ($byPackage[$package]['description'] === '') {
                    $byPackage[$package]['description'] = $this->summarizeDocs((string) ($entry['docs'] ?? ''));
                }
            }
        }

        $packages = array_values($byPackage);
        usort($packages, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $packages;
    }

    /**
     * @return array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     *     docs_url: string,
     * }|null
     */
    public function packageInfo(string $packagePath): ?array
    {
        $packagePath = trim($packagePath);
        if ($packagePath === '') {
            return null;
        }

        $moduleIds = [];
        $repo = '';
        $bestDocs = '';

        foreach ($this->moduleIndex() as $moduleId => $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $package = trim((string) ($entry['package'] ?? ''));
                if ($package !== $packagePath) {
                    continue;
                }

                $moduleIds[] = (string) $moduleId;
                $repo = $repo !== '' ? $repo : trim((string) ($entry['repo'] ?? ''));
                $docs = trim((string) ($entry['docs'] ?? ''));
                if (mb_strlen($docs) > mb_strlen($bestDocs)) {
                    $bestDocs = $docs;
                }
            }
        }

        if ($moduleIds === []) {
            return null;
        }

        $moduleIds = array_values(array_unique($moduleIds));
        sort($moduleIds);

        $catalogDescription = (string) ($this->catalogEntry($packagePath)['description'] ?? '');

        return [
            'path' => $packagePath,
            'repo' => $repo,
            'label' => $this->labelForPackage($packagePath, (string) ($this->catalogEntry($packagePath)['label'] ?? '')),
            'description' => $catalogDescription !== ''
                ? $catalogDescription
                : $this->formatDocsForModal($bestDocs),
            'module_ids' => $moduleIds,
            'docs_url' => 'https://caddyserver.com/docs/modules/'.rawurlencode($moduleIds[0]),
        ];
    }

    /**
     * @param  list<string>  $moduleIds
     * @return list<string>
     */
    public function packagesFromModuleIds(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        $index = $this->moduleIndex();
        $packages = [];

        foreach ($moduleIds as $moduleId) {
            $moduleId = trim($moduleId);
            if ($moduleId === '' || ! isset($index[$moduleId]) || ! is_array($index[$moduleId])) {
                continue;
            }

            foreach ($index[$moduleId] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $package = trim((string) ($entry['package'] ?? ''));
                if ($package !== '' && ! $this->isStandardPackage($package)) {
                    $packages[] = $package;
                }
            }
        }

        return array_values(array_unique($packages));
    }

    public function isStandardPackage(string $package): bool
    {
        return str_starts_with(trim($package), 'github.com/caddyserver/caddy/v2');
    }

    public function clearCache(): void
    {
        Cache::forget('caddy.module_registry.index');
    }

    /**
     * @return array{label?: string, description?: string}
     */
    private function catalogEntry(string $path): array
    {
        $catalog = (array) config('caddy_modules.catalog', []);

        return (array) ($catalog[$path] ?? []);
    }

    private function labelForPackage(string $package, string $catalogLabel = ''): string
    {
        if ($catalogLabel !== '') {
            return $catalogLabel;
        }

        $segment = basename(str_replace('\\', '/', $package));

        return str_replace(['-', '_'], ' ', $segment);
    }

    private function summarizeDocs(string $docs): string
    {
        $docs = trim(preg_replace('/\s+/', ' ', $docs) ?? '');

        if ($docs === '') {
            return '';
        }

        if (mb_strlen($docs) <= 180) {
            return $docs;
        }

        return rtrim(mb_substr($docs, 0, 177)).'…';
    }

    private function formatDocsForModal(string $docs): string
    {
        $docs = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\R{3,}/", "\n\n", $docs) ?? '') ?? '');

        if ($docs === '') {
            return '';
        }

        if (mb_strlen($docs) <= 500) {
            return $docs;
        }

        return rtrim(mb_substr($docs, 0, 497)).'…';
    }
}
