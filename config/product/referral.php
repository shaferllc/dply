<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Referral bonus (referrer reward)
    |--------------------------------------------------------------------------
    |
    | When a referred user’s organization completes a qualifying paid invoice
    | (Pro subscription prices from config/subscription.php), the referrer
    | receives a Stripe customer balance credit on their first billable org.
    | Set to 0 to record conversions in-app without applying Stripe credit.
    |
    */
    'bonus_credit_cents' => (int) env('REFERRAL_BONUS_CREDIT_CENTS', 1500),

    /*
    |--------------------------------------------------------------------------
    | Marketing copy
    |--------------------------------------------------------------------------
    */
    'bonus_description' => env('REFERRAL_BONUS_DESCRIPTION', 'credit toward your next invoice'),

];
