<?php

namespace Tests\Unit\Services;

use App\Services\Servers\LaravelQueueWorkCommandBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaravelQueueWorkCommandBuilderTest extends TestCase
{
    public function test_builds_minimal_command(): void
    {
        $cmd = (new LaravelQueueWorkCommandBuilder)->build();

        $this->assertStringContainsString('php artisan queue:work', $cmd);
        $this->assertStringContainsString('--queue=', $cmd);
        $this->assertStringContainsString('--sleep=3', $cmd);
        $this->assertStringContainsString('--timeout=60', $cmd);
    }

    public function test_includes_connection_when_set(): void
    {
        $cmd = (new LaravelQueueWorkCommandBuilder(connection: 'redis'))->build();

        $this->assertStringContainsString('redis', $cmd);
        $this->assertStringContainsString('queue:work', $cmd);
    }

    #[DataProvider('flagProvider')]
    public function test_respects_numeric_flags(
        int $timeout,
        int $sleep,
        int $tries,
        int $memory,
        int $maxTime,
        int $backoff,
    ): void {
        $cmd = (new LaravelQueueWorkCommandBuilder(
            timeout: $timeout,
            sleep: $sleep,
            tries: $tries,
            memory: $memory,
            maxTime: $maxTime,
            backoff: $backoff,
        ))->build();

        $this->assertStringContainsString('--timeout='.$timeout, $cmd);
        $this->assertStringContainsString('--sleep='.$sleep, $cmd);
        $this->assertStringContainsString('--tries='.$tries, $cmd);
        $this->assertStringContainsString('--memory='.$memory, $cmd);
        $this->assertStringContainsString('--max-time='.$maxTime, $cmd);
        if ($backoff > 0) {
            $this->assertStringContainsString('--backoff='.$backoff, $cmd);
        } else {
            $this->assertStringNotContainsString('--backoff=', $cmd);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, int}>
     */
    public static function flagProvider(): iterable
    {
        yield 'defaults' => [60, 3, 3, 128, 3600, 0];
        yield 'with_backoff' => [90, 5, 1, 256, 1800, 10];
    }

    public function test_shell_escape_arg_value_handles_empty_queue(): void
    {
        $this->assertSame("'default'", LaravelQueueWorkCommandBuilder::shellEscapeArgValue(''));
    }
}
