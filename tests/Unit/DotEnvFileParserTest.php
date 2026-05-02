<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sites\DotEnvFileParser;
use PHPUnit\Framework\TestCase;

class DotEnvFileParserTest extends TestCase
{
    public function test_parses_simple_assignments(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("FOO=bar\nBAZ=qux\n");

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $r['variables']);
        $this->assertSame([], $r['errors']);
    }

    public function test_skips_blank_lines_and_comments(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("# header comment\n\nFOO=bar\n\n# trailing\n");

        $this->assertSame(['FOO' => 'bar'], $r['variables']);
        $this->assertSame([], $r['errors']);
    }

    public function test_strips_surrounding_quotes(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("A=\"double\"\nB='single'\nC=plain\n");

        $this->assertSame(['A' => 'double', 'B' => 'single', 'C' => 'plain'], $r['variables']);
    }

    public function test_strips_inline_comment_on_unquoted_values_only(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("PLAIN=value # trailing comment\nQUOTED=\"value # in quotes\"\n");

        $this->assertSame('value', $r['variables']['PLAIN']);
        $this->assertSame('value # in quotes', $r['variables']['QUOTED']);
    }

    public function test_supports_export_prefix(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("export PATH_PREFIX=/opt/bin\n");

        $this->assertSame(['PATH_PREFIX' => '/opt/bin'], $r['variables']);
    }

    public function test_reports_missing_equals_sign(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("BROKEN_LINE\nVALID=ok\n");

        $this->assertSame(['VALID' => 'ok'], $r['variables']);
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('missing "="', $r['errors'][0]);
    }

    public function test_reports_invalid_key(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("lower-case-key=foo\n3LEADING_DIGIT=bar\nGOOD_KEY=ok\n");

        $this->assertSame(['GOOD_KEY' => 'ok'], $r['variables']);
        $this->assertCount(2, $r['errors']);
    }

    public function test_handles_empty_string_value(): void
    {
        $parser = new DotEnvFileParser();
        $r = $parser->parse("EMPTY=\nALSO_EMPTY=\"\"\n");

        $this->assertSame('', $r['variables']['EMPTY']);
        $this->assertSame('', $r['variables']['ALSO_EMPTY']);
    }
}
