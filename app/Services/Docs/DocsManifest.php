<?php

declare(strict_types=1);

namespace App\Services\Docs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Single source of truth for the published documentation set.
 *
 * Scans repo-root docs/*.md, reads each file's YAML front-matter, and returns a
 * cached, slug-keyed collection. A file is PUBLISHED iff it has front-matter
 * with a non-empty `title` and is not flagged `internal: true` / `published:
 * false`. Files without front-matter (internal specs, roadmaps, ADRs) are
 * excluded automatically — no allow-list to maintain.
 *
 * Front-matter fields: title (required), slug (defaults to the kebab filename —
 * set explicitly to preserve an existing URL), category, order, description,
 * group (optional, for the in-app contextual sidebar), internal, published.
 *
 * Route-backed "virtual" pages (config/docs.php `virtual`: api/connect-provider/
 * create-first-server) are folded in so callers see one list.
 */
final class DocsManifest
{
    private const CACHE_KEY = 'docs.manifest.v2';

    /**
     * Request-scoped memo of the published set. build() globs + YAML-parses every
     * docs/*.md, and find()/published() are called many times per request (e.g.
     * indexEntries() resolves a title per slug). Without this memo that's O(n²)
     * file I/O — locally, where the persistent cache below is intentionally
     * bypassed, it added seconds to every authenticated page. The memo is per
     * instance, so binding this as a scoped singleton keeps it to one build per
     * request while still reflecting on-disk doc edits on the next request.
     *
     * @var Collection<string, array<string, mixed>>|null
     */
    private ?Collection $memo = null;

    /**
     * Category display order. Anything unlisted sorts after these, alphabetically.
     */
    private const CATEGORY_ORDER = [
        'Getting started',
        'Servers',
        'Sites & deploys',
        'Edge',
        'Cloud',
        'Serverless',
        'Realtime',
        'Organization',
        'Billing',
        'Reference',
        'Guides',
    ];

    /**
     * Published docs keyed by slug.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function published(): Collection
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // Skip the persistent cache locally so editing a doc shows up on the next
        // request. The in-request memo above still prevents repeated rebuilds
        // within a single request.
        if (app()->environment('local')) {
            return $this->memo = collect($this->build());
        }

        return $this->memo = collect(Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->published()->get($slug);
    }

    /**
     * @return list<string>
     */
    public function publishedSlugs(): array
    {
        return $this->published()->keys()->all();
    }

    /**
     * Published docs grouped by category, both categories and docs in display order.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function byCategory(): Collection
    {
        return $this->published()
            ->sortBy([
                ['category_order', 'asc'],
                ['order', 'asc'],
                ['title', 'asc'],
            ])
            ->groupBy('category')
            ->sortBy(fn (Collection $docs): int => (int) $docs->first()['category_order']);
    }

    /**
     * Flat ordered list of file-backed docs (for prev/next paging).
     *
     * @return list<array<string, mixed>>
     */
    public function orderedList(): array
    {
        return $this->published()
            ->filter(fn (array $d): bool => ! empty($d['file']))
            ->sortBy([
                ['category_order', 'asc'],
                ['order', 'asc'],
                ['title', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Previous/next docs around the given slug, in global reading order.
     *
     * @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>}
     */
    public function prevNext(string $slug): array
    {
        $list = $this->orderedList();
        $idx = null;
        foreach ($list as $i => $doc) {
            if ($doc['slug'] === $slug) {
                $idx = $i;
                break;
            }
        }

        if ($idx === null) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $list[$idx - 1] ?? null,
            'next' => $list[$idx + 1] ?? null,
        ];
    }

    public function categoryOrder(string $category): int
    {
        $i = array_search($category, self::CATEGORY_ORDER, true);

        return $i === false ? count(self::CATEGORY_ORDER) : (int) $i;
    }

    /**
     * Contextual-sidebar guide groups, keyed by the `group` front-matter field
     * (edge/servers/sites/organization), in display order. Replaces the legacy
     * config('docs.groups').
     *
     * @return array<string, array{key: string, label: string, slugs: list<string>}>
     */
    public function groups(): array
    {
        $labels = [
            'edge' => 'Edge guides',
            'servers' => 'Server workspace guides',
            'sites' => 'Site workspace guides',
            'organization' => 'Organization guides',
        ];
        $order = array_flip(array_keys($labels));

        $buckets = $this->published()
            ->filter(fn (array $d): bool => is_string($d['group'] ?? null) && $d['group'] !== '')
            ->sortBy([['order', 'asc'], ['title', 'asc']])
            ->groupBy('group');

        return $buckets
            ->sortBy(fn (Collection $docs, string $key): int => $order[$key] ?? 99)
            ->map(fn (Collection $docs, string $key): array => [
                'key' => $key,
                'label' => $labels[$key] ?? Str::headline($key).' guides',
                'slugs' => $docs->pluck('slug')->all(),
            ])
            ->all();
    }

    public function groupForSlug(?string $slug): ?string
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $doc = $this->find($slug);

        return is_array($doc) && is_string($doc['group'] ?? null) && $doc['group'] !== ''
            ? $doc['group']
            : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(): array
    {
        $docs = [];

        foreach (File::glob(base_path('docs/*.md')) as $path) {
            $front = $this->frontMatter(File::get($path));
            if ($front === null) {
                continue; // no front-matter → internal/unpublished
            }

            $title = $front['title'] ?? null;
            if (! is_string($title) || trim($title) === '') {
                continue;
            }
            if (($front['internal'] ?? false) === true || ($front['published'] ?? true) === false) {
                continue;
            }

            $file = basename($path);
            $slug = isset($front['slug']) && is_string($front['slug']) && trim($front['slug']) !== ''
                ? Str::slug((string) $front['slug'])
                : Str::slug(Str::beforeLast($file, '.md'));

            $category = is_string($front['category'] ?? null) && trim($front['category']) !== ''
                ? trim((string) $front['category'])
                : 'Guides';

            $docs[$slug] = [
                'slug' => $slug,
                'title' => trim($title),
                'category' => $category,
                'category_order' => $this->categoryOrder($category),
                'order' => (int) ($front['order'] ?? 100),
                'description' => trim((string) ($front['description'] ?? '')),
                'group' => is_string($front['group'] ?? null) ? $front['group'] : null,
                'file' => $file,
                'route' => null,
            ];
        }

        // Fold in route-backed virtual pages so there is one canonical list.
        foreach ((array) config('docs.virtual', []) as $slug => $v) {
            if (! is_array($v) || isset($docs[$slug])) {
                continue;
            }

            $category = is_string($v['category'] ?? null) ? $v['category'] : 'Reference';

            $docs[$slug] = [
                'slug' => $slug,
                'title' => (string) ($v['title'] ?? $slug),
                'category' => $category,
                'category_order' => $this->categoryOrder($category),
                'order' => (int) ($v['order'] ?? 100),
                'description' => (string) ($v['summary'] ?? $v['description'] ?? ''),
                'group' => $v['group'] ?? null,
                'file' => $v['file'] ?? null,
                'route' => $v['route'] ?? null,
            ];
        }

        return $docs;
    }

    /**
     * Parse the leading `---` YAML front-matter block. Returns null when absent
     * or unparseable (treated as unpublished).
     *
     * @return array<string, mixed>|null
     */
    private function frontMatter(string $raw): ?array
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF"); // strip BOM
        if (! str_starts_with($raw, "---\n") && ! str_starts_with($raw, "---\r\n")) {
            return null;
        }

        $body = preg_replace('/^---\r?\n/', '', $raw, 1);
        $end = preg_split('/\r?\n---\r?\n/', (string) $body, 2);
        if (! is_array($end) || count($end) < 2) {
            return null;
        }

        try {
            $parsed = Yaml::parse($end[0]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }
}
