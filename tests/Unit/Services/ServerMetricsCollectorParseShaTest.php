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
}
