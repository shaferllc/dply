<?php

namespace App\Services\Logging;

/**
 * The canonical *stock* `config/logging.php` fingerprint per Laravel major
 * (Q6). Used by {@see LoggingDivergenceDetector} to decide whether a site's
 * committed logging config has been customised before dply replaces it.
 *
 * We fingerprint by channel→driver map rather than storing whole files: it's
 * the part that actually matters ("did they add/retype a channel?"), it's
 * immune to whitespace/comment/patch-version churn, and it never requires
 * executing the user's PHP. `config/logging.php`'s channel set has been stable
 * across Laravel 10–13, so the maintenance cost is a rare one-line addition.
 */
final class CanonicalLoggingStock
{
    /**
     * The stock channel→driver map shared by Laravel 10–13. `emergency` is the
     * one stock channel with no `driver` key (it only carries `path`); we model
     * it explicitly so a user's untouched emergency channel isn't flagged.
     *
     * @var array<string, string>
     */
    private const MODERN = [
        'stack' => 'stack',
        'single' => 'single',
        'daily' => 'daily',
        'slack' => 'slack',
        'papertrail' => 'monolog',
        'stderr' => 'monolog',
        'syslog' => 'syslog',
        'errorlog' => 'errorlog',
        'null' => 'monolog',
        'emergency' => '_path_', // sentinel: stock emergency has no driver
    ];

    /**
     * Stock channel→driver map for a Laravel major, or null when the major
     * isn't catalogued (detector then degrades to a soft warning).
     *
     * @return array<string, string>|null
     */
    public static function channels(int $major): ?array
    {
        return match (true) {
            $major >= 10 && $major <= 13 => self::MODERN,
            default => null,
        };
    }

    /** The stock default channel (`env('LOG_CHANNEL', 'stack')`). */
    public static function defaultChannel(int $major): string
    {
        return 'stack';
    }

    /** The stock deprecations channel (`env('LOG_DEPRECATIONS_CHANNEL', 'null')`). */
    public static function deprecationsChannel(int $major): string
    {
        return 'null';
    }

    public static function knows(int $major): bool
    {
        return self::channels($major) !== null;
    }
}
