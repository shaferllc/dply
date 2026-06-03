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
    |   STRIPE_PRICE_STANDARD_CLOUD=price_...              (flat dply Cloud platform fee, monthly)
    |   STRIPE_PRICE_STANDARD_CLOUD_YEARLY=price_...
    |   STRIPE_PRICE_STANDARD_CLOUD_USAGE=price_...         (metered Cloud resources, per-cent unit)
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
        // billable unit. See project_serverless_v1 memo. For BYO functions
        // (customer's own provider account) this is the entire dply charge; for
        // dply-managed functions it's the platform fee and metered usage +
        // managed DB/cache resources are billed on top (see dply.serverless).
        'serverless_cents' => 200,
        // Metered managed-serverless usage + resources billed in 1-cent Stripe
        // units (quantity = cents), like Edge/Cloud usage. Monthly only.
        'serverless_usage_unit_cents' => 1,
        // Markup applied to raw DO managed database/cache list prices when a
        // dply-managed function provisions them on dply's own account (dply pays
        // DO, so it must bill back with margin — same idea as cloud_markup_percent).
        'serverless_markup_percent' => (int) env('SUBSCRIPTION_SERVERLESS_MARKUP_PERCENT', 40),

        /*
        |----------------------------------------------------------------------
        | dply-managed servers — all-in cost-plus (replaces the tier fee)
        |----------------------------------------------------------------------
        |
        | Managed VMs run on dply-owned Hetzner infrastructure (dply pays
        | Hetzner), so they're billed provider-cost × markup as a single all-in
        | monthly price and do NOT count toward the per-server plan tier. Raw
        | values are approximate Hetzner monthly list prices in cents (USD,
        | verified 2026-05) keyed by the same server_type slug offered in
        | config/managed_servers.php. Billed via a metered Stripe line
        | (quantity = cents), like Cloud/Edge usage.
        */
        'managed_server_markup_percent' => (int) env('SUBSCRIPTION_MANAGED_SERVER_MARKUP_PERCENT', 60),
        'managed_server_cents' => [
            'cx22' => 450,
            'cx32' => 740,
            'cx42' => 1790,
            'cx52' => 3330,
        ],
        // Metered managed-server cost is billed in 1-cent Stripe units (quantity = cents).
        'managed_server_usage_unit_cents' => 1,
        // Closed-beta envelope. An org with organizations.beta_joined_at set is a
        // beta participant: the platform fee is waived, trial/soft-pause is
        // suppressed, and these caps replace the plan ceilings until the global
        // cutover. `byo_servers` is generous-but-bounded (a leaked invite can't
        // provision hundreds of boxes on a stolen cloud key via dply);
        // `managed_servers` is the single free CX22 grant; `sites` is roomy.
        // `cutover_at` is the global beta end date (Y-m-d or full datetime, null
        // = no end set yet). At cutover beta orgs fall to the normal trial and
        // the free CX22's comped_until expires. `managed_size` pins the free box.
        'beta' => [
            'byo_servers' => (int) env('SUBSCRIPTION_BETA_BYO_SERVERS', 5),
            'managed_servers' => (int) env('SUBSCRIPTION_BETA_MANAGED_SERVERS', 1),
            'sites' => (int) env('SUBSCRIPTION_BETA_SITES', 25),
            'managed_size' => env('SUBSCRIPTION_BETA_MANAGED_SIZE', 'cx22'),
            'cutover_at' => env('SUBSCRIPTION_BETA_CUTOVER_AT'),
            'invite_expiry_days' => (int) env('SUBSCRIPTION_BETA_INVITE_EXPIRY_DAYS', 30),
        ],
        // dply Cloud **platform fee** per live app — covers builds, deploys,
        // scaling, TLS, dashboards, and orchestration. This is NOT the whole
        // bill: Cloud apps run on dply-owned DigitalOcean infra (containers,
        // managed databases, buckets), so the metered provider resources below
        // are billed *on top* of this fee. A flat $5 alone loses money the
        // moment an app attaches a database (DO Postgres is $15+/mo). See the
        // managed-product billing investigation memo.
        'cloud_cents' => 500,
        // Flat per-site fee for first-party dply Edge (static/SSG delivery).
        // Edge is genuinely flat-eligible: Cloudflare Workers Paid is $5/mo per
        // *account* (amortized across the whole fleet) and R2/Pages egress is
        // free, so the marginal cost of another static site is ~$0.
        'edge_cents' => 200,
        // Edge delivery usage is billed in 1-cent Stripe units (quantity = cents).
        'edge_usage_unit_cents' => 1,

        /*
        |----------------------------------------------------------------------
        | dply Cloud — metered provider resources (cost-plus)
        |----------------------------------------------------------------------
        |
        | Cloud apps run on dply-owned DigitalOcean infrastructure, so dply pays
        | DO for every container, worker, and managed database and must bill it
        | back with margin. Raw values below are DO list prices (cents/month,
        | verified 2026-05); `cloud_markup_percent` is applied on top to produce
        | the customer rate. Billed alongside the flat `cloud_cents` platform fee
        | via a metered Stripe line (quantity = cents, like Edge usage).
        |
        | Container/worker tiers map to App Platform instance_size_slugs
        | (see DigitalOceanAppPlatformBackend / CloudWorker::SIZE_TIERS):
        |   small=basic-xxs $5, medium=basic-xs $10, large=basic-s $20,
        |   xlarge=basic-m $40, *-pro=apps-d-* dedicated ($29 → $78).
        | Database tiers map to DO Managed DB sizes (CloudDatabase::SIZE_TIERS):
        |   small=db-s-1vcpu-1gb $15, medium=db-s-1vcpu-2gb $30,
        |   large=db-s-2vcpu-4gb $60.
        | Buckets map to a DO Spaces subscription ($5 / 250 GiB).
        */
        'cloud_markup_percent' => (int) env('SUBSCRIPTION_CLOUD_MARKUP_PERCENT', 40),
        // Raw DO container cost (cents/mo) per portable size tier, per instance.
        'cloud_container_cents' => [
            'small' => 500,
            'medium' => 1000,
            'large' => 2000,
            'xlarge' => 4000,
            'small-pro' => 2900,
            'medium-pro' => 3400,
            'large-pro' => 3900,
            'xlarge-pro' => 7800,
        ],
        // Raw DO managed-database cost (cents/mo) per portable size tier.
        'cloud_database_cents' => [
            'small' => 1500,
            'medium' => 3000,
            'large' => 6000,
        ],
        // Raw DO Spaces cost (cents/mo) per attached bucket subscription.
        'cloud_bucket_cents' => 500,
        // Metered Cloud resources are billed in 1-cent Stripe units (quantity = cents).
        'cloud_usage_unit_cents' => 1,
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
            // Metered managed-serverless usage + resources line (per-cent unit), monthly only.
            'serverless_usage' => env('STRIPE_PRICE_STANDARD_SERVERLESS_USAGE', ''),
            // Metered managed-server (all-in cost-plus) line (per-cent unit), monthly only.
            'managed_server' => env('STRIPE_PRICE_STANDARD_MANAGED_SERVER', ''),
            'cloud' => env('STRIPE_PRICE_STANDARD_CLOUD', ''),
            'cloud_yearly' => env('STRIPE_PRICE_STANDARD_CLOUD_YEARLY', ''),
            // Metered Cloud provider-resource line (per-cent unit), monthly only.
            'cloud_usage' => env('STRIPE_PRICE_STANDARD_CLOUD_USAGE', ''),
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
