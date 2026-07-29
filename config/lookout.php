<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Managed Lookout billing
    |--------------------------------------------------------------------------
    |
    | Billing for the dply-managed Lookout error-tracking resource. Dark until
    | `billing_enabled` is true AND the per-tier Stripe price IDs are set in
    | config/subscription.php (standard.stripe.lookout_tiers) — until then the
    | billing computer adds no Lookout line, so projects provision free. This is
    | the same calibrate-then-flip "gate" pattern dply Logs uses.
    |
    | Each org gets `free_projects_per_org` managed projects at no charge (a
    | loss-leader); additional projects bill at their tier price. Tiers carry the
    | retention window + monthly event allowance the project is provisioned with
    | on the Lookout side, and the monthly price.
    |
    */

    'billing_enabled' => (bool) env('LOOKOUT_BILLING_ENABLED', false),

    'default_tier' => env('LOOKOUT_DEFAULT_TIER', 'starter'),

    'free_projects_per_org' => (int) env('LOOKOUT_FREE_PROJECTS_PER_ORG', 1),

    'tiers' => [
        'starter' => ['label' => 'Starter', 'retention_days' => 7, 'monthly_events' => 100_000, 'price_cents' => 1500],
        'growth' => ['label' => 'Growth', 'retention_days' => 30, 'monthly_events' => 1_000_000, 'price_cents' => 4900],
        'scale' => ['label' => 'Scale', 'retention_days' => 90, 'monthly_events' => 10_000_000, 'price_cents' => 14900],
    ],

];
