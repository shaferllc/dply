<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\ServerDateFormatterTest;

use App\Models\Server;
use App\Support\Servers\ServerDateFormatter;
use Illuminate\Support\Carbon;

function serverWithMeta(array $meta): Server
{
    $server = new Server;

    // forceFill bypasses fillable and lets us set meta without saving.
    $server->forceFill(['meta' => $meta]);

    return $server;
}
test('null input returns null', function () {
    expect(ServerDateFormatter::format(null, null))->toBeNull();
    expect(ServerDateFormatter::format('', null))->toBeNull();
});
test('default when no preference is absolute utc', function () {
    $dt = Carbon::parse('2026-05-05T04:59:02Z');
    $server = serverWithMeta([]);

    expect(ServerDateFormatter::format($dt, $server))->toBe('2026-05-05 04:59:02 UTC');
});
test('iso8601 format emits z suffix', function () {
    $dt = Carbon::parse('2026-05-05T04:59:02Z');
    $server = serverWithMeta(['date_format' => 'iso8601']);

    expect(ServerDateFormatter::format($dt, $server))->toBe('2026-05-05T04:59:02Z');
});
test('absolute local uses server meta timezone', function () {
    $dt = Carbon::parse('2026-05-05T04:59:02Z');
    $server = serverWithMeta([
        'date_format' => 'absolute_local',
        'timezone' => 'America/New_York', // EDT in May → UTC-4
    ]);

    // 04:59:02 UTC → 00:59:02 EDT
    expect(ServerDateFormatter::format($dt, $server))->toBe('2026-05-05 00:59:02 EDT');
});
test('short local format', function () {
    $dt = Carbon::parse('2026-05-05T04:59:02Z');
    $server = serverWithMeta([
        'date_format' => 'short_local',
        'timezone' => 'America/New_York',
    ]);

    expect(ServerDateFormatter::format($dt, $server))->toBe('May 5 · 12:59 AM');
});
test('relative format uses diff for humans', function () {
    Carbon::setTestNow('2026-05-05T05:00:00Z');
    try {
        $dt = Carbon::parse('2026-05-05T04:59:02Z'); // 58 seconds ago
        $server = serverWithMeta(['date_format' => 'relative']);

        // diffForHumans(parts: 1) collapses 58s to "less than a minute" or "58 seconds ago"
        // depending on Carbon version; both contain "ago".
        $rendered = ServerDateFormatter::format($dt, $server);
        expect($rendered)->not->toBeNull();
        $this->assertStringContainsString('ago', $rendered);
    } finally {
        Carbon::setTestNow();
    }
});
test('unknown key falls back to default', function () {
    $dt = Carbon::parse('2026-05-05T04:59:02Z');
    $server = serverWithMeta(['date_format' => 'totally-made-up-key']);

    // Unknown key resolves to the default (absolute_utc).
    expect(ServerDateFormatter::format($dt, $server))->toBe('2026-05-05 04:59:02 UTC');
});
test('resolve key returns default for null server', function () {
    expect(ServerDateFormatter::resolveKey(null))->toBe('absolute_utc');
});
test('string input is parsed', function () {
    $server = serverWithMeta(['date_format' => 'iso8601']);

    expect(ServerDateFormatter::format('2026-05-05 04:59:02 UTC', $server))->toBe('2026-05-05T04:59:02Z');
});
