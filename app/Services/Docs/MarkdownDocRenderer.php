<?php

namespace App\Services\Docs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarkdownDocRenderer
{
    /**
     * @return array{title: string, html: string, headings: list<array{id: string, text: string, level: int}>}
     */
    public function render(string $slug): array
    {
        [$filename, $title] = $this->resolvePage($slug);

        $path = base_path('docs/'.$filename);
        if (! File::isFile($path)) {
            throw new NotFoundHttpException;
        }

        $html = Str::markdown(File::get($path));
        $html = $this->injectHeadingIds($html);
        $headings = $this->headingsFromHtml($html);

        return [
            'title' => $title,
            'html' => $html,
            'headings' => $headings,
        ];
    }

    /**
     * @return array{0: string, 1: string} filename, title
     */
    private function resolvePage(string $slug): array
    {
        $pages = config('docs.markdown', []);
        $page = $pages[$slug] ?? null;

        if (is_array($page)) {
            $filename = $page['file'] ?? null;
            $title = $page['title'] ?? null;

            if (is_string($filename) && $filename !== '' && is_string($title) && $title !== '') {
                return [$filename, $title];
            }
        }

        $virtual = config('docs.virtual', []);
        $virtualPage = $virtual[$slug] ?? null;

        if (is_array($virtualPage) && isset($virtualPage['file'], $virtualPage['title'])) {
            $filename = $virtualPage['file'];
            $title = $virtualPage['title'];

            if (is_string($filename) && $filename !== '' && is_string($title) && $title !== '') {
                return [$filename, $title];
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * @return list<array{id: string, text: string, level: int}>
     */
    public function headingsFromHtml(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $headings = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            $level = match ($element->tagName) {
                'h2' => 2,
                'h3' => 3,
                default => null,
            };

            if ($level === null) {
                continue;
            }

            $id = trim((string) $element->getAttribute('id'));
            $text = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');

            if ($id === '' || $text === '') {
                continue;
            }

            $headings[] = [
                'id' => $id,
                'text' => $text,
                'level' => $level,
            ];
        }

        return $headings;
    }

    private function injectHeadingIds(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $usedIds = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }

            if (! in_array($element->tagName, ['h1', 'h2', 'h3', 'h4'], true)) {
                continue;
            }

            $existingId = trim((string) $element->getAttribute('id'));
            if ($existingId !== '') {
                $usedIds[$existingId] = true;

                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
            $baseId = Str::slug($text);

            if ($baseId === '') {
                continue;
            }

            $id = $baseId;
            $suffix = 2;

            while (isset($usedIds[$id])) {
                $id = $baseId.'-'.$suffix;
                $suffix++;
            }

            $usedIds[$id] = true;
            $element->setAttribute('id', $id);
        }

        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return $html;
        }

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }
}
