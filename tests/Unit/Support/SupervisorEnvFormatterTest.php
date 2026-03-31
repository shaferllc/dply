<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SupervisorEnvFormatter;
use Tests\TestCase;

class SupervisorEnvFormatterTest extends TestCase
{
    public function test_parse_lines_handles_comments_and_blanks(): void
    {
        $raw = "# c\nAPP_ENV=production\n\nFOO=bar baz\n";
        $this->assertSame([
            'APP_ENV' => 'production',
            'FOO' => 'bar baz',
        ], SupervisorEnvFormatter::parseLines($raw));
    }

    public function test_to_ini_fragment_escapes_quotes(): void
    {
        $s = SupervisorEnvFormatter::toIniFragment(['X' => 'say "hi"']);
        $this->assertStringContainsString('environment=', $s);
        $this->assertStringContainsString('say \\"hi\\"', $s);
    }
}
