<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use PHPUnit\Framework\TestCase;

class DotEnvFileWriterTest extends TestCase
{
    public function test_renders_simple_pairs(): void
    {
        $w = new DotEnvFileWriter;
        $out = $w->render(['B' => 'two', 'A' => 'one']);

        $this->assertSame("A=one\nB=two\n", $out);
    }

    public function test_quotes_values_with_whitespace_and_specials(): void
    {
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
    }

    public function test_escapes_quotes_and_backslashes_inside_quoted_values(): void
    {
        $w = new DotEnvFileWriter;
        $out = $w->render(['PATH_LIKE' => 'a\\b "c"']);

        $this->assertStringContainsString('PATH_LIKE="a\\\\b \\"c\\""', $out);
    }

    public function test_round_trips_through_parser(): void
    {
        $original = [
            'PLAIN' => 'x',
            'SPACED' => 'a b c',
            'HASH' => 'a#b',
            'QUOTED' => 'has "quotes"',
            'EMPTY' => '',
        ];
        $rendered = (new DotEnvFileWriter)->render($original);
        $parsed = (new DotEnvFileParser)->parse($rendered);

        $this->assertSame([], $parsed['errors']);
        ksort($original);
        $this->assertSame($original, $parsed['variables']);
    }

    public function test_empty_input_produces_empty_output(): void
    {
        $this->assertSame('', (new DotEnvFileWriter)->render([]));
    }
}
