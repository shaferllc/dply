<?php

declare(strict_types=1);

namespace App\Services\Serverless\Aws;

use InvalidArgumentException;

/**
 * Translates a standard cron expression into an AWS EventBridge schedule
 * expression.
 *
 * dply stores schedules as standard 5-field cron (what the OpenWhisk alarms
 * feed expects). EventBridge's `cron(...)` form differs in three ways, and
 * this class reconciles all three:
 *
 *  - it has a sixth field, year;
 *  - day-of-week is 1-7 with 1=Sunday (standard cron is 0-6 with 0=Sunday);
 *  - day-of-month and day-of-week cannot both be specified — exactly one
 *    must be the `?` wildcard.
 *
 * Pure and side-effect free, so the translation is exhaustively unit-tested.
 */
final class EventBridgeCronExpression
{
    /**
     * @throws InvalidArgumentException when the cron cannot be represented
     */
    public static function fromStandardCron(string $cron): string
    {
        $fields = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException('A schedule must be a standard 5-field cron expression.');
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $dayOfMonthWild = $dayOfMonth === '*';
        $dayOfWeekWild = $dayOfWeek === '*';

        if (! $dayOfMonthWild && ! $dayOfWeekWild) {
            throw new InvalidArgumentException('EventBridge cannot schedule on both a day-of-month and a day-of-week — use one or the other.');
        }

        if ($dayOfWeekWild) {
            // Keyed on day-of-month (or every day): day-of-week becomes `?`.
            $eventBridgeDayOfMonth = $dayOfMonthWild ? '*' : $dayOfMonth;
            $eventBridgeDayOfWeek = '?';
        } else {
            // Keyed on day-of-week: day-of-month becomes `?`.
            $eventBridgeDayOfMonth = '?';
            $eventBridgeDayOfWeek = self::shiftDayOfWeek($dayOfWeek);
        }

        return sprintf(
            'cron(%s %s %s %s %s *)',
            $minute,
            $hour,
            $eventBridgeDayOfMonth,
            $month,
            $eventBridgeDayOfWeek,
        );
    }

    /**
     * Shift each numeric day-of-week token from standard cron's 0-6 (0=Sun,
     * 7 also accepted for Sun) to EventBridge's 1-7 (1=Sun). Three-letter
     * names (MON, TUE, …) are valid in both and pass through unchanged.
     */
    private static function shiftDayOfWeek(string $field): string
    {
        return preg_replace_callback(
            '/\d+/',
            static fn (array $match): string => (string) (((int) $match[0]) % 7 + 1),
            $field,
        ) ?? $field;
    }
}
