<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Shared cache channel between the interactive release-hygiene scan job
 * ({@see \App\Jobs\ScanServerReleaseHygieneJob}) and the Livewire poller
 * ({@see \App\Livewire\Servers\Concerns\RunsServerReleaseHygieneScan}).
 *
 * The job streams progress frames here and writes a terminal result marker; the
 * poller reads both so the SSH scan runs off the request thread and the UI never
 * blocks (the scan can take ~120s, far past PHP's request cap). Mirrors the
 * progress/result split {@see \App\Services\Servers\WebserverCertsAggregator} uses
 * for the live-cert sweep.
 */
final class ServerReleaseHygieneScanStatus
{
    private const TTL_SECONDS = 600;

    private const MAX_FRAMES = 300;

    public static function reset(string $serverId): void
    {
        Cache::forget(self::progressKey($serverId));
        Cache::forget(self::resultKey($serverId));
    }

    /** Append streamed stdout (split into lines) to the progress log. */
    public static function append(string $serverId, string $chunk): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];
        $frames = self::progress($serverId);
        $t = count($frames);

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $frames[] = ['t' => $t++, 'line' => $line];
        }

        if (count($frames) > self::MAX_FRAMES) {
            $frames = array_slice($frames, -self::MAX_FRAMES);
        }

        Cache::put(self::progressKey($serverId), array_values($frames), self::TTL_SECONDS);
    }

    /** @return list<array{t: int, line: string}> */
    public static function progress(string $serverId): array
    {
        $frames = Cache::get(self::progressKey($serverId), []);

        return is_array($frames) ? array_values($frames) : [];
    }

    public static function markResult(string $serverId, bool $ok, ?string $error): void
    {
        Cache::put(self::resultKey($serverId), ['ok' => $ok, 'error' => $error], self::TTL_SECONDS);
    }

    /** @return array{ok: bool, error: ?string}|null */
    public static function result(string $serverId): ?array
    {
        $result = Cache::get(self::resultKey($serverId));

        if (! is_array($result)) {
            return null;
        }

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'error' => is_string($result['error'] ?? null) ? $result['error'] : null,
        ];
    }

    private static function progressKey(string $serverId): string
    {
        return "hygiene-scan:progress:{$serverId}";
    }

    private static function resultKey(string $serverId): string
    {
        return "hygiene-scan:result:{$serverId}";
    }
}
