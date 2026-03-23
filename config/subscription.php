<?php

/**
 * Subscription plans and Stripe price IDs.
 *
 * Set in .env:
 *   STRIPE_KEY=pk_...
 *   STRIPE_SECRET=sk_...
 *   STRIPE_WEBHOOK_SECRET=whsec_...
 *   STRIPE_PRICE_PRO_MONTHLY=price_...  (optional)
 *   STRIPE_PRICE_PRO_YEARLY=price_...   (optional)
 *
 * Leave price IDs empty for local/testing if you don't use Stripe.
 *
 * Limits (free/Starter tier = no active subscription or non-Pro plan):
 *   servers_free: max servers allowed when not on a Pro plan (default 3).
 * Pro (pro_monthly or pro_yearly) has unlimited servers.
 */

return [
    'limits' => [
        'servers_free' => (int) env('SUBSCRIPTION_SERVERS_FREE_LIMIT', 3),
    ],

    'plans' => [
        'pro_monthly' => [
            'id' => 'pro_monthly',
            'name' => 'Pro (monthly)',
            'price_id' => env('STRIPE_PRICE_PRO_MONTHLY', ''),
            'interval' => 'month',
            'description' => 'Pro plan billed monthly.',
        ],
        'pro_yearly' => [
            'id' => 'pro_yearly',
            'name' => 'Pro (yearly)',
            'price_id' => env('STRIPE_PRICE_PRO_YEARLY', ''),
            'interval' => 'year',
            'description' => 'Pro plan billed yearly.',
        ],
        /*
         * Optional per-seat add-on price. When present on the subscription, member
         * cap uses this line item's quantity (see Organization::seatCapFromSubscription).
         */
        'seat' => [
            'price_id' => env('STRIPE_PRICE_SEAT', ''),
        ],
    ],
];
