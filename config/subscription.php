<?php

/**
 * Subscription plans and Stripe price IDs.
 *
 * Set in .env:
 *   STRIPE_KEY=pk_...
 *   STRIPE_SECRET=sk_...
 *   STRIPE_WEBHOOK_SECRET=whsec_...
 *
 * Pricing — the committed model in project_pricing_model memo:
 *   $15/mo organization base + per-server tier fee. No coupon — tier prices
 *   are the only adjustment beyond the base. Stripe Checkout requires every
 *   line item to share a billing interval, so each tier has both a monthly
 *   and a yearly price; the yearly variant is 20% off the monthly × 12.
 *
 *   STRIPE_PRICE_STANDARD_BASE_MONTHLY=price_...
 *   STRIPE_PRICE_STANDARD_BASE_YEARLY=price_...
 *
 *   STRIPE_PRICE_STANDARD_TIER_XS=price_...
 *   STRIPE_PRICE_STANDARD_TIER_S=price_...
 *   STRIPE_PRICE_STANDARD_TIER_M=price_...
 *   STRIPE_PRICE_STANDARD_TIER_L=price_...
 *   STRIPE_PRICE_STANDARD_TIER_XL=price_...
 *
 *   STRIPE_PRICE_STANDARD_TIER_XS_YEARLY=price_...
 *   STRIPE_PRICE_STANDARD_TIER_S_YEARLY=price_...
 *   STRIPE_PRICE_STANDARD_TIER_M_YEARLY=price_...
 *   STRIPE_PRICE_STANDARD_TIER_L_YEARLY=price_...
 *   STRIPE_PRICE_STANDARD_TIER_XL_YEARLY=price_...
 *
 *   STRIPE_PRICE_ENTERPRISE=price_...              (manual Stripe sub for sales-led deals)
 */

return [
    'standard' => [
        'base_cents' => 1500,
        // included_credit_cents kept at 0 for back-compat: the legacy "first
        // server included" coupon model was retired (Stripe Checkout doesn't
        // accept amount_off + forever coupons, and interval-specific amount
        // coupons added complexity for marginal gain). DTOs and the marketing
        // calculator still read this — at 0 they just no-op the credit row.
        'included_credit_cents' => 0,
        'annual_discount_pct' => 20,
        'per_server_cap_cents' => 4000,
        'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),
        'soft_pause_days' => (int) env('SUBSCRIPTION_SOFT_PAUSE_DAYS', 30),
        // Servers younger than this are excluded from billing. Absorbs the
        // "spin up + test + kill in five minutes" case so customers aren't
        // nickel-and-dimed for transient infrastructure.
        'min_billable_age_days' => (int) env('SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS', 1),
        'tiers' => [
            'xs' => 200,
            's' => 500,
            'm' => 1000,
            'l' => 2000,
            'xl' => 4000,
        ],
        'stripe' => [
            'base_monthly' => env('STRIPE_PRICE_STANDARD_BASE_MONTHLY', ''),
            'base_yearly' => env('STRIPE_PRICE_STANDARD_BASE_YEARLY', ''),
            'tiers' => [
                'xs' => env('STRIPE_PRICE_STANDARD_TIER_XS', ''),
                's' => env('STRIPE_PRICE_STANDARD_TIER_S', ''),
                'm' => env('STRIPE_PRICE_STANDARD_TIER_M', ''),
                'l' => env('STRIPE_PRICE_STANDARD_TIER_L', ''),
                'xl' => env('STRIPE_PRICE_STANDARD_TIER_XL', ''),
            ],
            'tiers_yearly' => [
                'xs' => env('STRIPE_PRICE_STANDARD_TIER_XS_YEARLY', ''),
                's' => env('STRIPE_PRICE_STANDARD_TIER_S_YEARLY', ''),
                'm' => env('STRIPE_PRICE_STANDARD_TIER_M_YEARLY', ''),
                'l' => env('STRIPE_PRICE_STANDARD_TIER_L_YEARLY', ''),
                'xl' => env('STRIPE_PRICE_STANDARD_TIER_XL_YEARLY', ''),
            ],
        ],
    ],

    'enterprise' => [
        'stripe_price_id' => env('STRIPE_PRICE_ENTERPRISE', ''),
    ],
];
