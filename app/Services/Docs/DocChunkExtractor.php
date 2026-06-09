<?php

declare(strict_types=1);

namespace App\Services\Docs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DocChunkExtractor
{
    public function __construct(
        private readonly MarkdownDocRenderer $renderer,
    ) {}

    /**
     * @return array{title: string, excerpt: string, headings: list<string>}
     */
    public function excerptForSlug(string $slug, int $maxChars = 6000): array
    {
        $path = $this->resolvePath($slug);
        $markdown = File::get($path);
        $title = $this->resolveTitle($slug);
        $headings = $this->headingsFromMarkdown($markdown);
        $excerpt = trim($markdown);

        if (strlen($excerpt) > $maxChars) {
            $excerpt = substr($excerpt, 0, $maxChars).'…';
        }

        return [
            'title' => $title,
            'excerpt' => $excerpt,
            'headings' => $headings,
        ];
    }

    private function resolvePath(string $slug): string
    {
        $pages = config('docs.markdown', []);
        $page = $pages[$slug] ?? null;

        if (is_array($page) && is_string($page['file'] ?? null) && $page['file'] !== '') {
            $path = base_path('docs/'.$page['file']);
            if (File::isFile($path)) {
                return $path;
            }
        }

        $vmGuides = config('docs-vm-guides', []);
        $vmPage = $vmGuides[$slug] ?? null;
        if (is_array($vmPage) && is_string($vmPage['file'] ?? null) && $vmPage['file'] !== '') {
            $path = base_path('docs/'.$vmPage['file']);
            if (File::isFile($path)) {
                return $path;
            }
        }

        $virtual = config('docs.virtual', []);
        $virtualPage = $virtual[$slug] ?? null;
        if (is_array($virtualPage) && is_string($virtualPage['file'] ?? null) && $virtualPage['file'] !== '') {
            $path = base_path('docs/'.$virtualPage['file']);
            if (File::isFile($path)) {
                return $path;
            }
        }

        throw new NotFoundHttpException;
    }

    private function resolveTitle(string $slug): string
    {
        $pages = config('docs.markdown', []);
        $page = $pages[$slug] ?? null;
        if (is_array($page) && is_string($page['title'] ?? null) && $page['title'] !== '') {
            return $page['title'];
        }

        $vmGuides = config('docs-vm-guides', []);
        $vmPage = $vmGuides[$slug] ?? null;
        if (is_array($vmPage) && is_string($vmPage['title'] ?? null) && $vmPage['title'] !== '') {
            return $vmPage['title'];
        }

        $virtual = config('docs.virtual', []);
        $virtualPage = $virtual[$slug] ?? null;
        if (is_array($virtualPage) && is_string($virtualPage['title'] ?? null) && $virtualPage['title'] !== '') {
            return $virtualPage['title'];
        }

        return Str::headline(str_replace('-', ' ', $slug));
    }

    /**
     * @return list<string>
     */
    private function headingsFromMarkdown(string $markdown): array
    {
        $headings = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $matches) === 1) {
                $headings[] = trim($matches[1]);
            }
        }

        return $headings;
    }
}
