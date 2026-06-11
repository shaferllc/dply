<?php

declare(strict_types=1);

namespace Dply\LogParser;

/**
 * Parses Laravel / Monolog log text into structured records.
 *
 * Monolog's default LineFormatter emits:
 *
 *     [%datetime%] %channel%.%level_name%: %message% %context% %extra%
 *
 * e.g. `[2026-06-10 14:23:01] production.ERROR: Boom {"exception":"..."} []`
 *
 * Real-world logs are messier than that single line: exceptions write a stack
 * trace across many following lines that carry no header. This parser groups
 * those continuation lines onto the record they belong to (as `trace`), and
 * peels the trailing JSON `context` / `extra` blocks off the message when they
 * are present and decode cleanly — never guessing when they don't.
 *
 * Tolerant by contract: it never throws. Leading lines that don't belong to any
 * record (a truncated tail, a bare trace fragment) come back as
 * `['parsed' => false, 'raw' => ...]` so callers can still surface them.
 */
final class LaravelLogParser
{
    /**
     * Matches the start of a Monolog record and captures the header fields plus
     * everything after `LEVEL: ` as the (still-unsplit) body.
     */
    private const HEADER = '/^\[(?<datetime>[^\]]+)\]\s+(?<channel>[^.\s]+)\.(?<level>[A-Za-z]+):\s?(?<body>.*)$/';

    /**
     * Split raw log text into structured records, newest grouping preserved.
     *
     * @return list<array{
     *     parsed: bool,
     *     datetime?: ?\DateTimeImmutable,
     *     channel?: string,
     *     level?: string,
     *     message?: string,
     *     context?: ?array,
     *     extra?: ?array,
     *     trace?: list<string>,
     *     raw: string
     * }>
     */
    public function parse(string $raw): array
    {
        $records = [];
        /** @var array<string, mixed>|null $current */
        $current = null;
        $rawLines = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (preg_match(self::HEADER, $line, $m)) {
                if ($current !== null) {
                    $records[] = $this->finalize($current, $rawLines);
                }
                $current = $m;
                $rawLines = [$line];

                continue;
            }

            if ($current === null) {
                // A continuation line with no record to attach to (e.g. the log
                // was tailed mid-trace). Surface it rather than dropping it.
                if (trim($line) !== '') {
                    $records[] = ['parsed' => false, 'raw' => $line];
                }

                continue;
            }

            // Continuation of the in-progress record (stack trace, etc.).
            $current['trace'][] = rtrim($line);
            $rawLines[] = $line;
        }

        if ($current !== null) {
            $records[] = $this->finalize($current, $rawLines);
        }

        return $records;
    }

    /**
     * Turn a captured header (+ trailing continuation lines) into a record.
     *
     * @param  array<string, mixed>  $m
     * @param  list<string>  $rawLines
     * @return array<string, mixed>
     */
    private function finalize(array $m, array $rawLines): array
    {
        [$message, $context, $extra] = $this->splitBody((string) ($m['body'] ?? ''));

        $trace = array_values(array_filter(
            (array) ($m['trace'] ?? []),
            static fn ($l): bool => trim((string) $l) !== '',
        ));

        return [
            'parsed' => true,
            'datetime' => $this->parseDate((string) ($m['datetime'] ?? '')),
            'channel' => (string) ($m['channel'] ?? ''),
            'level' => strtoupper((string) ($m['level'] ?? '')),
            'message' => $message,
            'context' => $context,
            'extra' => $extra,
            'trace' => $trace,
            'raw' => implode("\n", $rawLines),
        ];
    }

    /**
     * Peel up to two trailing JSON blocks (`context` then `extra`) off the body,
     * leaving the human message. Only peels a block that decodes to an array, so
     * a message that merely ends in `}` is never mistaken for context.
     *
     * @return array{0: string, 1: ?array, 2: ?array}
     */
    private function splitBody(string $body): array
    {
        $body = rtrim($body);
        $extra = $this->peelTrailingJson($body);
        $context = $this->peelTrailingJson($body);

        // We peeled right-to-left, so the first peel is `extra`, the second is
        // `context`; restore the human-facing order.
        return [trim($body), $context, $extra];
    }

    /**
     * If $body ends with a balanced JSON array/object that decodes cleanly,
     * remove it from $body (by reference) and return the decoded value.
     */
    private function peelTrailingJson(string &$body): ?array
    {
        $trimmed = rtrim($body);
        $end = strlen($trimmed);
        if ($end === 0) {
            return null;
        }

        $close = $trimmed[$end - 1];
        $open = match ($close) {
            '}' => '{',
            ']' => '[',
            default => null,
        };
        if ($open === null) {
            return null;
        }

        // Scan left for the matching opener, respecting JSON string literals.
        $depth = 0;
        $inString = false;
        $escaped = false;
        $start = null;

        for ($i = $end - 1; $i >= 0; $i--) {
            $ch = $trimmed[$i];

            if ($inString) {
                // Walk back over an escaped char: a quote is a string boundary
                // only when not preceded by an (unescaped) backslash.
                if ($ch === '"' && ! $this->isEscapedAt($trimmed, $i)) {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;

                continue;
            }

            if ($ch === $close) {
                $depth++;
            } elseif ($ch === $open) {
                $depth--;
                if ($depth === 0) {
                    $start = $i;
                    break;
                }
            }
        }

        if ($start === null) {
            return null;
        }

        $candidate = substr($trimmed, $start);
        $decoded = json_decode($candidate, true);
        if (! is_array($decoded)) {
            return null;
        }

        $body = rtrim(substr($trimmed, 0, $start));

        return $decoded;
    }

    /** Whether the char at $i is escaped by an odd run of preceding backslashes. */
    private function isEscapedAt(string $s, int $i): bool
    {
        $backslashes = 0;
        for ($j = $i - 1; $j >= 0 && $s[$j] === '\\'; $j--) {
            $backslashes++;
        }

        return ($backslashes % 2) === 1;
    }

    /**
     * Monolog's default datetime is `Y-m-d H:i:s`, but a custom format may carry
     * micros and/or a timezone. Try the common shapes, then fall back to PHP's
     * own parser; null when nothing reads.
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i:s.u', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
