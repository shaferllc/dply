<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\ServerDateFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServerDateFormatterTest extends TestCase
{
    private function serverWithMeta(array $meta): Server
    {
        $server = new Server;
        // forceFill bypasses fillable and lets us set meta without saving.
        $server->forceFill(['meta' => $meta]);

        return $server;
    }

    public function test_null_input_returns_null(): void
    {
        $this->assertNull(ServerDateFormatter::format(null, null));
        $this->assertNull(ServerDateFormatter::format('', null));
    }

    public function test_default_when_no_preference_is_absolute_utc(): void
    {
        $dt = Carbon::parse('2026-05-05T04:59:02Z');
        $server = $this->serverWithMeta([]);

        $this->assertSame('2026-05-05 04:59:02 UTC', ServerDateFormatter::format($dt, $server));
    }

    public function test_iso8601_format_emits_z_suffix(): void
    {
        $dt = Carbon::parse('2026-05-05T04:59:02Z');
        $server = $this->serverWithMeta(['date_format' => 'iso8601']);

        $this->assertSame('2026-05-05T04:59:02Z', ServerDateFormatter::format($dt, $server));
    }

    public function test_absolute_local_uses_server_meta_timezone(): void
    {
        $dt = Carbon::parse('2026-05-05T04:59:02Z');
        $server = $this->serverWithMeta([
            'date_format' => 'absolute_local',
            'timezone' => 'America/New_York', // EDT in May → UTC-4
        ]);

        // 04:59:02 UTC → 00:59:02 EDT
        $this->assertSame('2026-05-05 00:59:02 EDT', ServerDateFormatter::format($dt, $server));
    }

    public function test_short_local_format(): void
    {
        $dt = Carbon::parse('2026-05-05T04:59:02Z');
        $server = $this->serverWithMeta([
            'date_format' => 'short_local',
            'timezone' => 'America/New_York',
        ]);

        $this->assertSame('May 5 · 12:59 AM', ServerDateFormatter::format($dt, $server));
    }

    public function test_relative_format_uses_diff_for_humans(): void
    {
        Carbon::setTestNow('2026-05-05T05:00:00Z');
        try {
            $dt = Carbon::parse('2026-05-05T04:59:02Z'); // 58 seconds ago
            $server = $this->serverWithMeta(['date_format' => 'relative']);

            // diffForHumans(parts: 1) collapses 58s to "less than a minute" or "58 seconds ago"
            // depending on Carbon version; both contain "ago".
            $rendered = ServerDateFormatter::format($dt, $server);
            $this->assertNotNull($rendered);
            $this->assertStringContainsString('ago', $rendered);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unknown_key_falls_back_to_default(): void
    {
        $dt = Carbon::parse('2026-05-05T04:59:02Z');
        $server = $this->serverWithMeta(['date_format' => 'totally-made-up-key']);

        // Unknown key resolves to the default (absolute_utc).
        $this->assertSame('2026-05-05 04:59:02 UTC', ServerDateFormatter::format($dt, $server));
    }

    public function test_resolve_key_returns_default_for_null_server(): void
    {
        $this->assertSame('absolute_utc', ServerDateFormatter::resolveKey(null));
    }

    public function test_string_input_is_parsed(): void
    {
        $server = $this->serverWithMeta(['date_format' => 'iso8601']);

        $this->assertSame(
            '2026-05-05T04:59:02Z',
            ServerDateFormatter::format('2026-05-05 04:59:02 UTC', $server),
        );
    }
}
