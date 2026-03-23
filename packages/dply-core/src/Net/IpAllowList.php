<?php

declare(strict_types=1);

namespace Dply\Core\Net;

/**
 * IPv4 exact match and CIDR checks for allow lists (webhooks, API tokens, etc.).
 */
final class IpAllowList
{
    /**
     * @param  array<int, string>  $allowed
     */
    public static function contains(string $ip, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($ip === $entry) {
                return true;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            return false;
        }
        $subnet = $m[1];
        $mask = (int) $m[2];
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subLong & $maskLong);
    }
}
