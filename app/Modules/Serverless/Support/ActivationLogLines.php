<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

/**
 * Filters OpenWhisk activation log lines down to ones that actually say
 * something.
 *
 * OpenWhisk returns logs as `<timestamp> <stream>: <message>`. At the end of
 * every activation its runtime proxy writes a sentinel
 * (`XXX_THE_END_OF_A_WHISK_ACTIVATION_XXX`) to BOTH stdout and stderr to mark
 * the boundary. The platform strips the sentinel text but still emits the
 * timestamped line, so each activation yields a pair of lines with a valid
 * prefix and an empty message:
 *
 *     2026-08-21T02:05:16.805709862Z stderr:
 *     2026-08-21T02:05:16.805948427Z stdout:
 *
 * A function invoked once a minute by the scheduler therefore accumulates two
 * meaningless lines per minute, which is enough to bury real output entirely —
 * and to make a function that has never logged anything look like it is
 * logging constantly.
 *
 * Anything that does not parse as a prefixed line is KEPT. A runtime that
 * frames its output differently should show up as-is rather than vanish; the
 * only thing being dropped here is a line we can positively identify as empty.
 */
final class ActivationLogLines
{
    /** Written to both streams by the OpenWhisk proxy to close an activation. */
    private const SENTINEL = 'XXX_THE_END_OF_A_WHISK_ACTIVATION_XXX';

    /** `<timestamp> <stream>: <message>` */
    private const LINE = '/^(\S+)\s+(stdout|stderr):[ \t]?(.*)$/s';

    /**
     * @param  iterable<mixed>  $lines
     * @return list<string>
     */
    public static function meaningful(iterable $lines): array
    {
        $kept = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            if (self::isNoise($line)) {
                continue;
            }

            $kept[] = $line;
        }

        return $kept;
    }

    private static function isNoise(string $line): bool
    {
        if (trim($line) === '') {
            return true;
        }

        if (str_contains($line, self::SENTINEL)) {
            return true;
        }

        if (preg_match(self::LINE, $line, $matches) !== 1) {
            // Unrecognised framing — keep it rather than guess.
            return false;
        }

        return trim($matches[3]) === '';
    }
}
