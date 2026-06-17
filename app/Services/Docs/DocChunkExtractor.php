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
        private readonly DocsManifest $manifest,
    ) {}

    /**
     * @return array{title: string, excerpt: string, headings: list<string>}
     */
    /** @return array<string, mixed> */
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
        $doc = $this->manifest->find($slug);
        if (is_array($doc) && ! empty($doc['file'])) {
            $path = base_path('docs/'.$doc['file']);
            if (File::isFile($path)) {
                return $path;
            }
        }

        throw new NotFoundHttpException;
    }

    private function resolveTitle(string $slug): string
    {
        $doc = $this->manifest->find($slug);
        if (is_array($doc) && is_string($doc['title'] ?? null) && $doc['title'] !== '') {
            return $doc['title'];
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
