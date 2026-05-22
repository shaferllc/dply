<?php


namespace Tests\Unit\Services\LaravelQueueWorkCommandBuilderTest;
use App\Services\Servers\LaravelQueueWorkCommandBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

test('builds minimal command', function () {
    $cmd = (new LaravelQueueWorkCommandBuilder)->build();

    $this->assertStringContainsString('php artisan queue:work', $cmd);
    $this->assertStringContainsString('--queue=', $cmd);
    $this->assertStringContainsString('--sleep=3', $cmd);
    $this->assertStringContainsString('--timeout=60', $cmd);
});

test('includes connection when set', function () {
    $cmd = (new LaravelQueueWorkCommandBuilder(connection: 'redis'))->build();

    $this->assertStringContainsString('redis', $cmd);
    $this->assertStringContainsString('queue:work', $cmd);
});

test('respects numeric flags', function (int $timeout, int $sleep, int $tries, int $memory, int $maxTime, int $backoff) {
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
})->with('flagProvider');

/**
 * @return iterable<string, array{int, int, int, int, int, int}>
 */
dataset('flagProvider', function () {
    yield 'defaults' => [60, 3, 3, 128, 3600, 0];
    yield 'with_backoff' => [90, 5, 1, 256, 1800, 10];
});

test('shell escape arg value handles empty queue', function () {
    expect(LaravelQueueWorkCommandBuilder::shellEscapeArgValue(''))->toBe("'default'");
});
