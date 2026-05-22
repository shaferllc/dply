<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Formats datetimes per the server's saved display preference
 * (`meta.date_format`, default `absolute_utc`). Used in the Server
 * workspace where the operator opted into a specific shape — Metrics,
 * Insights, Run history etc. all funnel through this helper instead
 * of hardcoded `format('Y-m-d H:i:s T')` calls.
 *
 * Adding a new option:
 *   1. Register it in config('server_settings.date_formats').
 *   2. Add a case in {@see self::format()}.
 */
class ServerDateFormatter
{
    public const DEFAULT_KEY = 'absolute_utc';

    /**
     * Resolve a datetime to the human-facing string for the given server.
     * `null` input → null output (callers can `?? '—'` if they want a
     * placeholder).
     */
    public static function format(DateTimeInterface|string|null $value, ?Server $server): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $carbon = $value instanceof DateTimeInterface
            ? Carbon::instance(\DateTimeImmutable::createFromInterface($value))
            : Carbon::parse((string) $value);

        $key = self::resolveKey($server);
        $tz = self::resolveTimezone($server);

        return match ($key) {
            'iso8601' => $carbon->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'relative' => $carbon->diffForHumans(now(), [
                'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                'parts' => 1,
            ]),
            'short_local' => $carbon->copy()->setTimezone($tz)->format('M j · g:i A'),
            'absolute_local' => $carbon->copy()->setTimezone($tz)->format('Y-m-d H:i:s T'),
            default => $carbon->copy()->utc()->format('Y-m-d H:i:s \U\T\C'),
        };
    }

    /**
     * Read the server's stored format preference, falling back to the
     * default when missing or pointing at a now-removed key.
     */
    public static function resolveKey(?Server $server): string
    {
        $meta = is_array($server?->meta ?? null) ? $server->meta : [];
        $key = is_string($meta['date_format'] ?? null) ? (string) $meta['date_format'] : '';
        $allowed = array_keys((array) config('server_settings.date_formats', []));

        return $key !== '' && in_array($key, $allowed, true) ? $key : self::DEFAULT_KEY;
    }

    /**
     * Server-scoped display timezone (set in the same Reference settings
     * tab as the date format). Falls back to the app default when the
     * server hasn't picked one — same convention the existing UI uses.
     */
    public static function resolveTimezone(?Server $server): string
    {
        $meta = is_array($server?->meta ?? null) ? $server->meta : [];
        $tz = is_string($meta['timezone'] ?? null) ? (string) $meta['timezone'] : '';

        return $tz !== '' ? $tz : (string) config('app.timezone', 'UTC');
    }
}
