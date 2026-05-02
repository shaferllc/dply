<?php

namespace App\Support;

class HostnameValidator
{
    public static function isValid(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return false;
        }

        if (strlen($hostname) > 253 || str_starts_with($hostname, '.') || str_ends_with($hostname, '.')) {
            return false;
        }

        if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return false;
        }

        return ! filter_var($hostname, FILTER_VALIDATE_IP);
    }
}
