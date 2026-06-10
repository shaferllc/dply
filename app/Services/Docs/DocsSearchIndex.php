<?php

declare(strict_types=1);

namespace App\Services\Docs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Builds the client-side search index consumed by the Cmd+K palette: one entry
 * per published doc with title, category, description, url, headings, and a
 * trimmed plain-text body. Cached forever and flushed by `docs:flush`.
 */
final class DocsSearchIndex
{
    public const CACHE_KEY = 'docs.search-index.v1';

    public function __construct(
        private readonly DocsManifest $manifest,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function cached(): array
    {
        if (app()->environment('local')) {
            return $this->build();
        }

        return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->build());
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(): array
    {
        return $this->manifest->published()
            ->sortBy([['category_order', 'asc'], ['order', 'asc'], ['title', 'asc']])
            ->values()
            ->map(function (array $doc): array {
                $headings = [];
                $body = '';

                if (! empty($doc['file'])) {
                    $path = base_path('docs/'.$doc['file']);
                    if (File::isFile($path)) {
                        $markdown = $this->stripFrontMatter(File::get($path));
                        $headings = $this->headings($markdown);
                        $body = Str::limit($this->plainText($markdown), 2500, '');
                    }
                }

                return [
                    'slug' => $doc['slug'],
                    'title' => $doc['title'],
                    'category' => $doc['category'],
                    'description' => $doc['description'],
                    'url' => $this->urlFor($doc),
                    'headings' => $headings,
                    'body' => $body,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private function urlFor(array $doc): string
    {
        if (! empty($doc['route']) && is_string($doc['route'])) {
            try {
                return route($doc['route']);
            } catch (\Throwable) {
                // fall through
            }
        }

        return route('docs.markdown', ['slug' => $doc['slug']]);
    }

    /**
     * @return list<string>
     */
    private function headings(string $markdown): array
    {
        preg_match_all('/^#{1,3}\s+(.+?)\s*#*$/m', $markdown, $m);

        return collect($m[1] ?? [])
            ->map(fn (string $h): string => trim(preg_replace('/[`*_]/', '', $h)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function stripFrontMatter(string $raw): string
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        if (! str_starts_with($raw, "---\n") && ! str_starts_with($raw, "---\r\n")) {
            return $raw;
        }
        $body = preg_replace('/^---\r?\n.*?\r?\n---\r?\n/s', '', $raw, 1);

        return is_string($body) ? $body : $raw;
    }

    private function plainText(string $markdown): string
    {
        // Drop fenced code blocks, then strip the common markdown punctuation so
        // the body is searchable prose, not syntax.
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/`[^`]*`/', ' ', $text) ?? $text;
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text; // links/images → label
        $text = preg_replace('/^[#>\-\*\|\s]+/m', ' ', $text) ?? $text;          // list/heading/table markers
        $text = preg_replace('/[*_#`>]/', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim((string) $text);
    }
}
