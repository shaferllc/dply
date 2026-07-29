<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\AnsiHtml;

test('renders ansi colors as html spans', function () {
    $raw = "hello \x1B[33mworld\x1B[39m!";
    $html = AnsiHtml::toHtml($raw);

    expect($html)->toContain('style="color:#facc15"')
        ->and($html)->toContain('world')
        ->and($html)->not->toContain('[33m')
        ->and($html)->not->toContain("\x1B");
});

test('escapes html in log text', function () {
    $html = AnsiHtml::toHtml("<script>alert(1)</script>\x1B[32mok\x1B[0m");

    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->not->toContain('<script>');
});

test('resets styles on sgr 0', function () {
    $html = AnsiHtml::toHtml("\x1B[1;31mbad\x1B[0mplain");

    expect($html)->toContain('font-weight:700')
        ->and($html)->toContain('</span>plain');
});
