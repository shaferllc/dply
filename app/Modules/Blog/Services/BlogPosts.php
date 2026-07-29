<?php

declare(strict_types=1);

namespace App\Modules\Blog\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Scans, parses, and renders the build-in-public blog posts under content/blog/*.md.
 *
 * Mirrors the Docs module's manifest approach (front-matter via Symfony YAML,
 * Markdown via Str::markdown / league-commonmark, forever-cache with a flush
 * command) but is self-contained so the Blog module carries no dependency on Docs.
 */
final class BlogPosts
{
    public const CACHE_KEY = 'blog.manifest.v1';

    /**
     * All published posts, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $build = fn (): array => $this->scan();

        // Local: skip the persistent cache so edits show on the next request.
        $posts = app()->isLocal() ? $build() : Cache::rememberForever(self::CACHE_KEY, $build);

        return collect($posts);
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->all()->firstWhere('slug', $slug);
    }

    /** @param  array<string, mixed>  $post */
    public function renderHtml(array $post): string
    {
        $body = $this->stripFrontMatter(File::get((string) $post['file']));

        return Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function scan(): array
    {
        $posts = [];

        foreach (File::glob(base_path('content/blog/*.md')) as $file) {
            $raw = File::get($file);
            $fm = $this->frontMatter($raw);

            $title = trim((string) ($fm['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (($fm['published'] ?? true) === false) {
                continue;
            }

            $slug = trim((string) ($fm['slug'] ?? ''));
            if ($slug === '') {
                $slug = Str::of(basename($file, '.md'))->slug()->value();
            }

            $date = $this->resolveDate($file, $fm['date'] ?? null);

            $tags = $fm['tags'] ?? [];
            $tags = is_array($tags) ? $tags : array_map('trim', explode(',', (string) $tags));

            $type = strtolower(trim((string) ($fm['type'] ?? 'post')));
            $type = $type === 'deep-dive' ? 'deep-dive' : 'post';

            $words = str_word_count(strip_tags($this->stripFrontMatter($raw)));

            $posts[] = [
                'slug' => $slug,
                'title' => $title,
                'summary' => trim((string) ($fm['summary'] ?? $fm['description'] ?? '')),
                'date' => $date->toDateString(),
                'date_human' => $date->isoFormat('MMMM D, YYYY'),
                'timestamp' => $date->getTimestamp(),
                'tags' => array_values(array_filter(array_map('trim', $tags))),
                'type' => $type,
                'is_deep_dive' => $type === 'deep-dive',
                'reading_minutes' => max(1, (int) ceil($words / 200)),
                'file' => $file,
            ];
        }

        usort($posts, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $posts;
    }

    /**
     * Resolve a post's date. The YYYY-MM-DD filename prefix is the source of
     * truth (it always matches the slug); front-matter `date` is a tolerant
     * fallback — Symfony YAML may hand back a DateTime or a Unix timestamp, not
     * just a string, so handle all three before giving up to the file mtime.
     */
    private function resolveDate(string $file, mixed $fmDate): Carbon
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename($file), $m)) {
            try {
                return Carbon::parse($m[1]);
            } catch (\Throwable) {
                // fall through
            }
        }

        if ($fmDate instanceof \DateTimeInterface) {
            return Carbon::instance($fmDate);
        }
        if (is_int($fmDate) || (is_string($fmDate) && ctype_digit(trim($fmDate)))) {
            return Carbon::createFromTimestamp((int) $fmDate);
        }
        if (is_string($fmDate) && trim($fmDate) !== '') {
            try {
                return Carbon::parse(trim($fmDate));
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::createFromTimestamp(File::lastModified($file));
    }

    /** @return array<string, mixed> */
    private function frontMatter(string $raw): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $raw, $m)) {
            return [];
        }

        try {
            $data = Yaml::parse($m[1]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function stripFrontMatter(string $raw): string
    {
        return preg_replace('/^---\r?\n.*?\r?\n---\r?\n/s', '', $raw, 1) ?? $raw;
    }
}
