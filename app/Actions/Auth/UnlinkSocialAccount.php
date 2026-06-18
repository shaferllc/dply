<?php

namespace App\Actions\Auth;

use App\Models\User;

final class UnlinkSocialAccount
{
    /**
     * Whether the user may unlink a social account without locking themselves out.
     */
    public static function allowed(User $user): bool
    {
        if (filled($user->getRawOriginal('password'))) {
            return true;
        }

        if ($user->socialAccounts()->count() > 1) {
            return true;
        }

        return $user->passkeys()->exists();
    }

    public static function denyMessage(): string
    {
        return __('Set a password or add a passkey before unlinking your only sign-in method.');
    }
}
