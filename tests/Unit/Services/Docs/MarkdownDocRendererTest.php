<?php

namespace Tests\Unit\Services\Docs;

use App\Services\Docs\MarkdownDocRenderer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('markdown doc renderer renders edge overview with headings', function () {
    $renderer = app(MarkdownDocRenderer::class);

    $result = $renderer->render('edge-overview');

    expect($result['title'])->toBe('Edge overview')
        ->and($result['html'])->toContain('dply')
        ->and($result['headings'])->not->toBeEmpty();

    $firstHeading = $result['headings'][0];
    expect($firstHeading)->toHaveKeys(['id', 'text', 'level'])
        ->and($result['html'])->toContain('id="'.$firstHeading['id'].'"');
});

test('markdown doc renderer extracts h2 and h3 headings', function () {
    $renderer = app(MarkdownDocRenderer::class);

    $method = new \ReflectionMethod($renderer, 'injectHeadingIds');
    $method->setAccessible(true);
    $withIds = $method->invoke($renderer, '<h2>Section A</h2><p>Text</p><h3>Subsection</h3>');

    $headings = $renderer->headingsFromHtml($withIds);

    expect($headings)->toHaveCount(2)
        ->and($headings[0]['level'])->toBe(2)
        ->and($headings[1]['level'])->toBe(3);
});

test('markdown doc renderer throws for unknown slug', function () {
    app(MarkdownDocRenderer::class)->render('not-a-real-slug');
})->throws(NotFoundHttpException::class);

test('api virtual slug renders http api markdown', function () {
    $renderer = app(MarkdownDocRenderer::class);

    $result = $renderer->render('api');

    expect($result['title'])->toBe('HTTP API')
        ->and($result['html'])->not->toBe('');
});
