<?php

declare(strict_types=1);

namespace Tests\Unit\Support\SupervisorEnvFormatterTest;

use App\Support\SupervisorEnvFormatter;

test('parse lines handles comments and blanks', function () {
    $raw = "# c\nAPP_ENV=production\n\nFOO=bar baz\n";
    expect(SupervisorEnvFormatter::parseLines($raw))->toBe([
        'APP_ENV' => 'production',
        'FOO' => 'bar baz',
    ]);
});
test('to ini fragment escapes quotes', function () {
    $s = SupervisorEnvFormatter::toIniFragment(['X' => 'say "hi"']);
    $this->assertStringContainsString('environment=', $s);
    $this->assertStringContainsString('say \\"hi\\"', $s);
});
