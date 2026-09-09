<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Validates operator-entered "allow from" / route-destination values.
 *
 * Extracted from ManagesDatabaseEngineLifecycle, where it was a private trait
 * method: WorkspaceNetworking::addRoute() also called $this->isValidRemoteCidr()
 * but does not use that trait, so every attempt to add a route hit an undefined
 * method. One shared implementation now backs both.
 */
final class RemoteCidr
{
    /**
     * Whether $value is a comma-separated list of concrete CIDR blocks.
     *
     * '' and 'any' are deliberately NOT valid: they are the wildcard sentinels
     * callers handle separately, not a specific range to write into a rule.
     */
    public static function isValid(string $value): bool
    {
        if ($value === '' || $value === 'any') {
            return false;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));

        // The original fell through to `return true` here, so a value of "," or
        // ", ," validated as a legitimate CIDR list. Nothing to validate means
        // invalid.
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! str_contains($part, '/')) {
                return false;
            }

            [$ip, $prefix] = explode('/', $part, 2);

            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }

            if (! is_numeric($prefix) || (int) $prefix < 0 || (int) $prefix > 128) {
                return false;
            }
        }

        return true;
    }
}
