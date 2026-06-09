<?php

declare(strict_types=1);

namespace Tests\Unit\DotEnvFileWriterTest;

use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;

test('renders simple pairs', function () {
    $w = new DotEnvFileWriter;
    $out = $w->render(['B' => 'two', 'A' => 'one']);

    expect($out)->toBe("A=one\nB=two\n");
});
test('quotes values with whitespace and specials', function () {
    $w = new DotEnvFileWriter;
    $out = $w->render([
        'PLAIN' => 'simple',
        'WITH_SPACE' => 'two words',
        'WITH_HASH' => 'a#b',
        'WITH_EQ' => 'a=b',
    ]);

    $this->assertStringContainsString('PLAIN=simple', $out);
    $this->assertStringContainsString('WITH_SPACE="two words"', $out);
    $this->assertStringContainsString('WITH_HASH="a#b"', $out);
    $this->assertStringContainsString('WITH_EQ="a=b"', $out);
});
test('escapes quotes and backslashes inside quoted values', function () {
    $w = new DotEnvFileWriter;
    $out = $w->render(['PATH_LIKE' => 'a\\b "c"']);

    $this->assertStringContainsString('PATH_LIKE="a\\\\b \\"c\\""', $out);
});
test('round trips through parser', function () {
    $original = [
        'PLAIN' => 'x',
        'SPACED' => 'a b c',
        'HASH' => 'a#b',
        'QUOTED' => 'has "quotes"',
        'EMPTY' => '',
    ];
    $rendered = (new DotEnvFileWriter)->render($original);
    $parsed = (new DotEnvFileParser)->parse($rendered);

    expect($parsed['errors'])->toBe([]);
    ksort($original);
    expect($parsed['variables'])->toBe($original);
});
test('empty input produces empty output', function () {
    expect((new DotEnvFileWriter)->render([]))->toBe('');
});
