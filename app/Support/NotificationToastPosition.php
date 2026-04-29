<?php

namespace App\Support;

use App\Models\User;

final class NotificationToastPosition
{
    public static function resolvedFor(?User $user): string
    {
        $default = (string) config('user_preferences.defaults.notification_position');
        if ($user === null) {
            return $default;
        }

        $pos = $user->mergedUiPreferences()['notification_position'] ?? $default;
        $allowed = array_keys(config('user_preferences.notification_positions', []));

        return in_array($pos, $allowed, true) ? $pos : $default;
    }
}
