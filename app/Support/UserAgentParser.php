<?php

namespace App\Support;

class UserAgentParser
{
    /**
     * Parse a user agent string into a short readable label (e.g. "Chrome on macOS").
     */
    public static function parse(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return __('Unknown device');
        }

        $browser = static::detectBrowser($userAgent);
        $platform = static::detectPlatform($userAgent);

        if ($browser && $platform) {
            return $browser.' on '.$platform;
        }

        if ($browser) {
            return $browser;
        }

        if ($platform) {
            return $platform;
        }

        return __('Unknown device');
    }

    private static function detectBrowser(string $ua): ?string
    {
        if (str_contains($ua, 'Edg/')) {
            return 'Edge';
        }
        if (str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Chromium')) {
            return 'Chrome';
        }
        if (str_contains($ua, 'Firefox/')) {
            return 'Firefox';
        }
        if (str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome')) {
            return 'Safari';
        }
        if (str_contains($ua, 'Opera') || str_contains($ua, 'OPR/')) {
            return 'Opera';
        }

        return null;
    }

    private static function detectPlatform(string $ua): ?string
    {
        if (str_contains($ua, 'Windows')) {
            return 'Windows';
        }
        if (str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh')) {
            return 'macOS';
        }
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            return 'iOS';
        }
        if (str_contains($ua, 'Android')) {
            return 'Android';
        }
        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }
}
