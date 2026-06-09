<?php

namespace Tests\Unit\Services\ServerMetricsCollectorParseShaTest;

use App\Services\Servers\ServerMetricsCollector;
use ReflectionMethod;

test('parse remote script sha from buffer', function () {
    $collector = app(ServerMetricsCollector::class);
    $m = new ReflectionMethod(ServerMetricsCollector::class, 'parseRemoteScriptShaFromBuffer');
    $m->setAccessible(true);

    $buf = "DPLY_SCRIPT_SHA=abc123missing\n{\"cpu_pct\":1}\n";
    expect($m->invoke($collector, $buf))->toBe('abc123missing');

    $buf2 = "noise\nDPLY_SCRIPT_SHA=deadbeef\n{\"x\":1}";
    expect($m->invoke($collector, $buf2))->toBe('deadbeef');

    expect($m->invoke($collector, '{"cpu_pct":1}'))->toBeNull();
});

test('metrics parse failure message classifies common failures', function () {
    $collector = app(ServerMetricsCollector::class);
    $method = new ReflectionMethod(ServerMetricsCollector::class, 'metricsParseFailureMessage');
    $method->setAccessible(true);

    $this->assertStringContainsString(
        'returned no output over SSH',
        $method->invoke($collector, '', null)
    );

    $this->assertStringContainsString(
        'monitor script is missing',
        $method->invoke($collector, "DPLY_SCRIPT_SHA=MISSING\n", 'MISSING')
    );

    $this->assertStringContainsString(
        'shell output instead of metrics JSON',
        $method->invoke($collector, "DPLY_SCRIPT_SHA=abc123\nLast login: today\n", 'abc123')
    );
});

test('extract metrics json line prefers marked payload', function () {
    $collector = app(ServerMetricsCollector::class);
    $method = new ReflectionMethod(ServerMetricsCollector::class, 'extractMetricsJsonLine');
    $method->setAccessible(true);

    $buffer = implode("\n", [
        'Last login: today',
        'DPLY_SCRIPT_SHA=abc123',
        'DPLY_METRICS_JSON_BEGIN',
        '{"cpu_pct":1}',
        'DPLY_METRICS_JSON_END',
        'logout',
    ]);

    expect($method->invoke($collector, $buffer))->toBe('{"cpu_pct":1}');
});

test('normalize payload preserves extended metrics types', function () {
    $collector = app(ServerMetricsCollector::class);

    $normalized = $collector->normalizePayload([
        'cpu_pct' => '10.55',
        'mem_pct' => '20.0',
        'disk_pct' => '30.1',
        'load_1m' => '0.52',
        'load_5m' => '0.31',
        'load_15m' => '0.21',
        'mem_total_kb' => '1000000',
        'mem_available_kb' => '640000',
        'swap_total_kb' => '512000',
        'swap_used_kb' => '128000',
        'disk_total_bytes' => '100000000',
        'disk_used_bytes' => '50000000',
        'disk_free_bytes' => '50000000',
        'inode_pct_root' => '43.8',
        'cpu_count' => '8',
        'load_per_cpu_1m' => '0.06',
        'uptime_seconds' => '7200',
        'rx_bytes_per_sec' => '8192.25',
        'tx_bytes_per_sec' => '4096.13',
    ]);

    expect($normalized['cpu_pct'])->toBe(10.55);
    expect($normalized['mem_available_kb'])->toBe(640000);
    expect($normalized['swap_used_kb'])->toBe(128000);
    expect($normalized['disk_free_bytes'])->toBe(50000000);
    expect($normalized['inode_pct_root'])->toBe(43.8);
    expect($normalized['cpu_count'])->toBe(8);
    expect($normalized['load_per_cpu_1m'])->toBe(0.06);
    expect($normalized['uptime_seconds'])->toBe(7200);
    expect($normalized['rx_bytes_per_sec'])->toBe(8192.25);
    expect($normalized['tx_bytes_per_sec'])->toBe(4096.13);
});
