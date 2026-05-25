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
        $html = $this->transformTablesToCards($html);
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

    private function transformTablesToCards(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $document = $this->loadHtmlFragment($html);
        $tables = [];

        foreach ($document->getElementsByTagName('table') as $table) {
            if ($table instanceof \DOMElement) {
                $tables[] = $table;
            }
        }

        foreach ($tables as $table) {
            $replacement = $this->tableToCards($document, $table);

            if ($replacement === null) {
                continue;
            }

            $table->parentNode?->replaceChild($replacement, $table);
        }

        return $this->serializeFragment($document);
    }

    private function tableToCards(\DOMDocument $document, \DOMElement $table): ?\DOMElement
    {
        $headers = $this->tableHeaderCells($document, $table);
        $rows = $this->tableBodyRows($document, $table);

        if ($rows === []) {
            return null;
        }

        $columnCount = max(count($headers), ...array_map(fn (array $row): int => count($row), $rows));

        if ($columnCount < 2) {
            return null;
        }

        if ($columnCount === 2 && $this->isTransposedComparison($headers, $rows)) {
            return $this->buildTransposedComparison($document, $headers, $rows);
        }

        return $this->buildSpecCardList($document, $headers, $rows);
    }

    /**
     * @return list<string>
     */
    private function tableHeaderCells(\DOMDocument $document, \DOMElement $table): array
    {
        $headers = [];

        foreach ($table->getElementsByTagName('thead') as $thead) {
            if (! $thead instanceof \DOMElement) {
                continue;
            }

            foreach ($thead->getElementsByTagName('tr') as $tr) {
                if (! $tr instanceof \DOMElement) {
                    continue;
                }

                foreach ($tr->getElementsByTagName('th') as $th) {
                    if ($th instanceof \DOMElement) {
                        $headers[] = $this->cellInnerHtml($document, $th);
                    }
                }
            }
        }

        if ($headers !== []) {
            return $headers;
        }

        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof \DOMElement) {
                continue;
            }

            $firstRowHeaders = [];

            foreach ($tr->getElementsByTagName('th') as $th) {
                if ($th instanceof \DOMElement) {
                    $firstRowHeaders[] = $this->cellInnerHtml($document, $th);
                }
            }

            if ($firstRowHeaders !== []) {
                return $firstRowHeaders;
            }

            break;
        }

        return $headers;
    }

    /**
     * @return list<list<string>>
     */
    private function tableBodyRows(\DOMDocument $document, \DOMElement $table): array
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tbody') as $tbody) {
            if (! $tbody instanceof \DOMElement) {
                continue;
            }

            foreach ($tbody->getElementsByTagName('tr') as $tr) {
                if (! $tr instanceof \DOMElement) {
                    continue;
                }

                $row = $this->rowCells($document, $tr);

                if ($row !== []) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        $skippedHeader = false;

        foreach ($table->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof \DOMElement) {
                continue;
            }

            if (! $skippedHeader && $tr->getElementsByTagName('th')->length > 0) {
                $skippedHeader = true;

                continue;
            }

            $row = $this->rowCells($document, $tr);

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function rowCells(\DOMDocument $document, \DOMElement $tr): array
    {
        $cells = [];

        foreach ($tr->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (! in_array($child->tagName, ['td', 'th'], true)) {
                continue;
            }

            $cells[] = $this->cellInnerHtml($document, $child);
        }

        return $cells;
    }

    private function cellInnerHtml(\DOMDocument $document, \DOMElement $cell): string
    {
        $html = '';

        foreach ($cell->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function isTransposedComparison(array $headers, array $rows): bool
    {
        if (count($headers) !== 2 || count($rows) < 2) {
            return false;
        }

        if ($this->cellPlainText($headers[0]) === '' || $this->cellPlainText($headers[1]) === '') {
            return false;
        }

        $keyValueHeaders = [
            'field',
            'purpose',
            'setting',
            'symptom',
            'likely cause',
            'status',
            'meaning',
            'log type',
            'where',
            'option',
            'description',
            'tab',
            'shows',
            'domain',
            'record type',
            'dply cname target',
            'framework',
            'detected',
            'adapter',
            'dply runs',
            'worker output',
            'assets dir',
            'phase',
            'what landed',
        ];

        $leftHeader = strtolower($this->cellPlainText($headers[0]));

        foreach ($keyValueHeaders as $header) {
            if ($leftHeader === $header || str_starts_with($leftHeader, $header.' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function buildTransposedComparison(\DOMDocument $document, array $headers, array $rows): \DOMElement
    {
        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('class', 'docs-compare-list');

        $columns = $document->createElement('div');
        $columns->setAttribute('class', 'docs-compare-columns');

        foreach ([0, 1] as $columnIndex) {
            $card = $document->createElement('article');
            $card->setAttribute('class', 'docs-compare-card');

            $title = $document->createElement('h4');
            $title->setAttribute('class', 'docs-compare-card__title');
            $this->appendHtmlFragment($document, $title, $headers[$columnIndex]);
            $card->appendChild($title);

            $list = $document->createElement('ul');
            $list->setAttribute('class', 'docs-compare-card__list');

            foreach ($rows as $row) {
                if (! isset($row[$columnIndex]) || $this->cellPlainText($row[$columnIndex]) === '') {
                    continue;
                }

                $item = $document->createElement('li');
                $this->appendHtmlFragment($document, $item, $row[$columnIndex]);
                $list->appendChild($item);
            }

            $card->appendChild($list);
            $columns->appendChild($card);
        }

        $wrapper->appendChild($columns);

        return $wrapper;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function buildSpecCardList(\DOMDocument $document, array $headers, array $rows): \DOMElement
    {
        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('class', 'docs-spec-list');

        foreach ($rows as $row) {
            $card = $document->createElement('article');
            $card->setAttribute('class', 'docs-spec-card');

            $titleText = $row[0] ?? '';
            $fieldStart = 1;

            if ($this->cellPlainText($titleText) === '' && isset($row[1])) {
                $titleText = $headers[1] ?? 'Details';
                $fieldStart = 1;
            }

            if ($this->cellPlainText($titleText) !== '') {
                $title = $document->createElement('h4');
                $title->setAttribute('class', 'docs-spec-card__title');
                $this->appendHtmlFragment($document, $title, $titleText);
                $card->appendChild($title);
            }

            $dl = $document->createElement('dl');
            $dl->setAttribute('class', 'docs-spec-card__fields');

            for ($index = $fieldStart; $index < count($row); $index++) {
                $value = $row[$index];

                if ($this->cellPlainText($value) === '') {
                    continue;
                }

                $label = $headers[$index] ?? '';
                $labelText = $this->cellPlainText($label);
                $hideLabel = count($row) - $fieldStart === 1
                    && in_array(strtolower($labelText), ['purpose', 'meaning', 'description'], true);

                if (! $hideLabel && $labelText !== '') {
                    $dt = $document->createElement('dt');
                    $this->appendHtmlFragment($document, $dt, $label);
                    $dl->appendChild($dt);
                }

                $dd = $document->createElement('dd');
                if ($hideLabel) {
                    $dd->setAttribute('class', 'docs-spec-card__value-only');
                }
                $this->appendHtmlFragment($document, $dd, $value);
                $dl->appendChild($dd);
            }

            if ($dl->hasChildNodes()) {
                $card->appendChild($dl);
            }

            if ($card->hasChildNodes()) {
                $wrapper->appendChild($card);
            }
        }

        return $wrapper;
    }

    private function cellPlainText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
    }

    private function loadHtmlFragment(string $html): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function serializeFragment(\DOMDocument $document): string
    {
        $root = $document->documentElement;

        if (! $root instanceof \DOMElement) {
            return '';
        }

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    private function appendHtmlFragment(\DOMDocument $document, \DOMElement $parent, string $html): void
    {
        if ($html === '') {
            return;
        }

        $fragmentDocument = $this->loadHtmlFragment($html);
        $fragmentRoot = $fragmentDocument->documentElement;

        if (! $fragmentRoot instanceof \DOMElement) {
            return;
        }

        foreach ($fragmentRoot->childNodes as $child) {
            $parent->appendChild($document->importNode($child, true));
        }
    }
}
