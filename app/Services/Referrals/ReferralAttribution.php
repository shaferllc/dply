<?php

namespace App\Services\Referrals;

use App\Models\User;

class ReferralAttribution
{
    /**
     * Link a new user to a referrer from session (set via ?referrer= on any guest request).
     */
    public static function assignFromSession(User $user): void
    {
        $code = session()->pull('referral_code');
        if (! is_string($code) || $code === '') {
            return;
        }

        $referrer = User::query()->where('referral_code', $code)->whereKeyNot($user->id)->first();
        if (! $referrer) {
            return;
        }

        if ($user->referred_by_user_id !== null) {
            return;
        }

        $user->forceFill(['referred_by_user_id' => $referrer->id])->save();
    }
}
