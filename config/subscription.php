<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription plans and Stripe price IDs.
    |--------------------------------------------------------------------------
    |
    | Set in .env:
    |   STRIPE_KEY=pk_...
    |   STRIPE_SECRET=sk_...
    |   STRIPE_WEBHOOK_SECRET=whsec_...
    |
    | Pricing — the committed model in project_pricing_model memo:
    |   $15/mo organization base + per-server tier fee. No coupon — tier prices
    |   are the only adjustment beyond the base. Stripe Checkout requires every
    |   line item to share a billing interval, so each tier has both a monthly
    |   and a yearly price; the yearly variant is 20% off the monthly × 12.
    |
    |   STRIPE_PRICE_STANDARD_BASE_MONTHLY=price_...
    |   STRIPE_PRICE_STANDARD_BASE_YEARLY=price_...
    |
    |   STRIPE_PRICE_STANDARD_TIER_XS=price_...
    |   STRIPE_PRICE_STANDARD_TIER_S=price_...
    |   STRIPE_PRICE_STANDARD_TIER_M=price_...
    |   STRIPE_PRICE_STANDARD_TIER_L=price_...
    |   STRIPE_PRICE_STANDARD_TIER_XL=price_...
    |
    |   STRIPE_PRICE_STANDARD_TIER_XS_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_TIER_S_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_TIER_M_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_TIER_L_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_TIER_XL_YEARLY=price_...
    |
    |   STRIPE_PRICE_STANDARD_SERVERLESS=price_...         (flat per-function fee, monthly)
    |   STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY=price_...  (flat per-function fee, yearly)
    |   STRIPE_PRICE_STANDARD_CLOUD=price_...              (flat per dply Cloud app, monthly)
    |   STRIPE_PRICE_STANDARD_CLOUD_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_EDGE=price_...               (flat per dply Edge site, monthly)
    |   STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_...
    |
    |   STRIPE_PRICE_ENTERPRISE=price_...              (manual Stripe sub for sales-led deals)
    */

    'standard' => [
        // Flat plans metered by BYO server COUNT (not size). Customers pay
        // their own provider for server size; dply's fee scales with how many
        // servers it manages. Mirrors the proven Ploi/Forge/RunCloud model and
        // sits inside the $8–39 market cluster. Managed products (serverless,
        // Cloud, Edge) bill a la carte per unit on top of any plan — including
        // Free — because they run on dply-owned infra. See
        // docs/PRICING_AND_REVENUE.md.
        'annual_discount_pct' => 20,
        'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),
        'soft_pause_days' => (int) env('SUBSCRIPTION_SOFT_PAUSE_DAYS', 30),
        // Servers younger than this are excluded from the count. Absorbs the
        // "spin up + test + kill in five minutes" case so customers aren't
        // nickel-and-dimed for transient infrastructure.
        'min_billable_age_days' => (int) env('SUBSCRIPTION_MIN_BILLABLE_AGE_DAYS', 1),
        // Ordered cheapest → most expensive. `max_servers` is the inclusive
        // server-count ceiling for the plan; null means unlimited. The resolver
        // picks the cheapest plan whose ceiling covers the org's server count.
        // `max_sites` is the inclusive ceiling on how many sites an org on this
        // plan may run (null = unlimited). Enforced as a hard block at site
        // creation; the plan tier itself is still chosen by server count.
        'plans' => [
            'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1],
            'starter' => ['label' => 'Starter', 'price_cents' => 900, 'max_servers' => 3, 'max_sites' => 10],
            'pro' => ['label' => 'Pro', 'price_cents' => 1900, 'max_servers' => 10, 'max_sites' => 30],
            'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null],
        ],
        // Flat per-function fee for serverless (FaaS) targets. A serverless
        // function has no vCPU/RAM, so it isn't spec-tiered — it's its own
        // billable unit. See project_serverless_v1 memo.
        'serverless_cents' => 200,
        // Flat per-app fee for first-party dply Cloud (long-running containers).
        'cloud_cents' => 500,
        // Flat per-site fee for first-party dply Edge (static/SSG delivery).
        'edge_cents' => 200,
        // Edge delivery usage is billed in 1-cent Stripe units (quantity = cents).
        'edge_usage_unit_cents' => 1,
        // --- Legacy size-tier keys (being retired in the plan migration) ---
        // Retained so the not-yet-migrated billing dashboard, analytics, and
        // cost cards keep functioning until each is moved onto the plan model.
        // Remove once every consumer reads `plans` instead of `tiers`/base.
        'base_cents' => 0,
        'included_credit_cents' => 0,
        'per_server_cap_cents' => 4000,
        'tiers' => [
            'xs' => 200,
            's' => 500,
            'm' => 1000,
            'l' => 2000,
            'xl' => 4000,
        ],
        /*
        | Cost observatory — comparison baselines for billing analytics.
        | forge_per_server_cents mirrors Laravel Forge Hobby ($12/server/mo).
        */
        'observatory' => [
            'forge_per_server_cents' => (int) env('SUBSCRIPTION_FORGE_PER_SERVER_CENTS', 1200),
            'eur_to_usd_rate' => (float) env('SUBSCRIPTION_EUR_TO_USD_RATE', 1.08),
        ],
        'stripe' => [
            // One recurring price per paid plan, per interval. Free has no price
            // (a $0 plan never creates a Stripe subscription).
            'plans' => [
                'starter' => env('STRIPE_PRICE_STANDARD_STARTER', ''),
                'pro' => env('STRIPE_PRICE_STANDARD_PRO', ''),
                'business' => env('STRIPE_PRICE_STANDARD_BUSINESS', ''),
            ],
            'plans_yearly' => [
                'starter' => env('STRIPE_PRICE_STANDARD_STARTER_YEARLY', ''),
                'pro' => env('STRIPE_PRICE_STANDARD_PRO_YEARLY', ''),
                'business' => env('STRIPE_PRICE_STANDARD_BUSINESS_YEARLY', ''),
            ],
            'serverless' => env('STRIPE_PRICE_STANDARD_SERVERLESS', ''),
            'serverless_yearly' => env('STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY', ''),
            'cloud' => env('STRIPE_PRICE_STANDARD_CLOUD', ''),
            'cloud_yearly' => env('STRIPE_PRICE_STANDARD_CLOUD_YEARLY', ''),
            'edge' => env('STRIPE_PRICE_STANDARD_EDGE', ''),
            'edge_yearly' => env('STRIPE_PRICE_STANDARD_EDGE_YEARLY', ''),
            'edge_usage' => env('STRIPE_PRICE_STANDARD_EDGE_USAGE', ''),
            // --- Legacy size-tier Stripe prices (retired with the migration) ---
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
