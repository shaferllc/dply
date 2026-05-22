<?php

declare(strict_types=1);

namespace Tests\Unit\DotEnvFileParserTest;
use App\Services\Sites\DotEnvFileParser;
test('parses simple assignments', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("FOO=bar\nBAZ=qux\n");

    expect($r['variables'])->toBe(['FOO' => 'bar', 'BAZ' => 'qux']);
    expect($r['errors'])->toBe([]);
});
test('skips blank lines and comments', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("# header comment\n\nFOO=bar\n\n# trailing\n");

    expect($r['variables'])->toBe(['FOO' => 'bar']);
    expect($r['errors'])->toBe([]);
});
test('strips surrounding quotes', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("A=\"double\"\nB='single'\nC=plain\n");

    expect($r['variables'])->toBe(['A' => 'double', 'B' => 'single', 'C' => 'plain']);
});
test('strips inline comment on unquoted values only', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("PLAIN=value # trailing comment\nQUOTED=\"value # in quotes\"\n");

    expect($r['variables']['PLAIN'])->toBe('value');
    expect($r['variables']['QUOTED'])->toBe('value # in quotes');
});
test('supports export prefix', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("export PATH_PREFIX=/opt/bin\n");

    expect($r['variables'])->toBe(['PATH_PREFIX' => '/opt/bin']);
});
test('reports missing equals sign', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("BROKEN_LINE\nVALID=ok\n");

    expect($r['variables'])->toBe(['VALID' => 'ok']);
    expect($r['errors'])->toHaveCount(1);
    $this->assertStringContainsString('missing "="', $r['errors'][0]);
});
test('reports invalid key', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("lower-case-key=foo\n3LEADING_DIGIT=bar\nGOOD_KEY=ok\n");

    expect($r['variables'])->toBe(['GOOD_KEY' => 'ok']);
    expect($r['errors'])->toHaveCount(2);
});
test('handles empty string value', function () {
    $parser = new DotEnvFileParser;
    $r = $parser->parse("EMPTY=\nALSO_EMPTY=\"\"\n");

    expect($r['variables']['EMPTY'])->toBe('');
    expect($r['variables']['ALSO_EMPTY'])->toBe('');
});
