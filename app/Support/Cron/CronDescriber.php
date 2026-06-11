<?php

declare(strict_types=1);

namespace App\Support\Cron;

use Lorisleiva\CronTranslator\CronParsingException;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * Turns a raw 5-field cron expression into a human sentence ("Every day at
 * 3:00am") for display next to the expression in the Schedule / Cron / Backups
 * UI. Wraps {@see CronTranslator} so the rest of the app never has to think
 * about its exceptions: anything it can't parse (a macro like `@reboot`, a
 * 6-field expression, an empty value) simply returns null and the caller falls
 * back to showing the raw expression alone.
 */
final class CronDescriber
{
    public static function describe(?string $expression, bool $twentyFourHour = false): ?string
    {
        $expression = trim((string) $expression);
        if ($expression === '') {
            return null;
        }

        try {
            return CronTranslator::translate($expression, timeFormat24hours: $twentyFourHour);
        } catch (CronParsingException) {
            return null;
        }
    }
}
