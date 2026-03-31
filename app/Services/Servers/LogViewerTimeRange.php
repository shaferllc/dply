<?php

namespace App\Services\Servers;

use Carbon\CarbonImmutable;

/**
 * Best-effort filter of log lines to those whose leading timestamp looks newer than the cutoff.
 * Unparseable lines are kept so we do not drop data silently.
 */
class LogViewerTimeRange
{
    public static function filterLines(string $text, int $sinceMinutes): string
    {
        if ($sinceMinutes <= 0) {
            return $text;
        }

        $cutoff = CarbonImmutable::now()->subMinutes($sinceMinutes);
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            return $text;
        }

        $kept = [];
        foreach ($lines as $line) {
            $t = self::guessTimeStartOfLine($line);
            if ($t === null || $t->greaterThanOrEqualTo($cutoff)) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private static function guessTimeStartOfLine(string $line): ?CarbonImmutable
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // ISO-like: 2026-03-30 12:00:00 or 2026-03-30T12:00:00
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) {
            try {
                return CarbonImmutable::parse($m[1], config('app.timezone'));
            } catch (\Throwable) {
            }
        }

        // Nginx: [30/Mar/2026:12:00:00 +0000]
        if (preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}) ([+\-]\d{4})\]/', $line, $m)) {
            try {
                return CarbonImmutable::createFromFormat('d/M/Y:H:i:s O', $m[1].' '.$m[2], config('app.timezone'));
            } catch (\Throwable) {
            }
        }

        // journal short-iso: 2026-03-30T12:00:00+00:00
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-Z]\d*:?\d*:?\d*)/', $line, $m)) {
            try {
                return CarbonImmutable::parse($m[1]);
            } catch (\Throwable) {
            }
        }

        // Syslog: Mar 30 12:00:00 (current year assumed)
        if (preg_match('/^([A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
            try {
                return CarbonImmutable::parse($m[1].' '.now()->year, config('app.timezone'));
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
