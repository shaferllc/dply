<?php

namespace Tests\Unit\Services;

use App\Services\Servers\ServerMetricsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\TestCase;

#[CoversClass(ServerMetricsCollector::class)]
class ServerMetricsCollectorParseShaTest extends TestCase
{
    public function test_parse_remote_script_sha_from_buffer(): void
    {
        $collector = app(ServerMetricsCollector::class);
        $m = new ReflectionMethod(ServerMetricsCollector::class, 'parseRemoteScriptShaFromBuffer');
        $m->setAccessible(true);

        $buf = "DPLY_SCRIPT_SHA=abc123missing\n{\"cpu_pct\":1}\n";
        $this->assertSame('abc123missing', $m->invoke($collector, $buf));

        $buf2 = "noise\nDPLY_SCRIPT_SHA=deadbeef\n{\"x\":1}";
        $this->assertSame('deadbeef', $m->invoke($collector, $buf2));

        $this->assertNull($m->invoke($collector, '{"cpu_pct":1}'));
    }

    public function test_metrics_parse_failure_message_classifies_common_failures(): void
    {
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
    }

    public function test_extract_metrics_json_line_prefers_marked_payload(): void
    {
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

        $this->assertSame('{"cpu_pct":1}', $method->invoke($collector, $buffer));
    }

    public function test_normalize_payload_preserves_extended_metrics_types(): void
    {
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

        $this->assertSame(10.55, $normalized['cpu_pct']);
        $this->assertSame(640000, $normalized['mem_available_kb']);
        $this->assertSame(128000, $normalized['swap_used_kb']);
        $this->assertSame(50000000, $normalized['disk_free_bytes']);
        $this->assertSame(43.8, $normalized['inode_pct_root']);
        $this->assertSame(8, $normalized['cpu_count']);
        $this->assertSame(0.06, $normalized['load_per_cpu_1m']);
        $this->assertSame(7200, $normalized['uptime_seconds']);
        $this->assertSame(8192.25, $normalized['rx_bytes_per_sec']);
        $this->assertSame(4096.13, $normalized['tx_bytes_per_sec']);
    }
}
