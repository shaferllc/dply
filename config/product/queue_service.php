<?php

/*
|--------------------------------------------------------------------------
| dply Queue — the managed job queue
|--------------------------------------------------------------------------
|
| Runtime dials and per-plan entitlements for the managed queue product.
| The product SURFACE is gated by the Pennant flag `surface.queue` in
| config/features.php — per the layering rules documented at
| config/features.php:11-26, Pennant answers "does this org get the product"
| and this file answers "how does it behave and what does it cost".
|
| See docs/adr/dply-queue.md.
|
| Note the deliberate distinction from Laravel's own config/queue.php: that
| one configures the queue dply ITSELF runs (Redis + Horizon). This one
| configures the queue dply SELLS. They share nothing.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | Off until the data plane ships. Mirrors `server_logs.enabled`.
    |
    */

    'enabled' => filter_var(true, FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Public endpoint
    |--------------------------------------------------------------------------
    |
    | The base URL customers point their queue client at. Built from
    | DPLY_PUBLIC_APP_URL when unset, for the same reason the serverless log
    | ingest URL is: APP_URL is typically a local *.test in development and a
    | customer's app could never reach it.
    |
    */

    'public_url' => env('DPLY_QUEUE_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Reservation
    |--------------------------------------------------------------------------
    |
    | `default_visibility` is how long a claimed job stays invisible before it
    | becomes claimable again. `lease_grace` is added to the job's own declared
    | timeout when clamping — the clamp is what makes Laravel's
    | `retry_after > timeout` misconfiguration unrepresentable here.
    |
    | `max_visibility` bounds the damage a client can do by asking for an
    | enormous lease on a job it then abandons.
    |
    */

    'reservation' => [
        'default_visibility_seconds' => (int) env('DPLY_QUEUE_DEFAULT_VISIBILITY', 60),
        'lease_grace_seconds' => (int) env('DPLY_QUEUE_LEASE_GRACE', 15),
        'max_visibility_seconds' => (int) env('DPLY_QUEUE_MAX_VISIBILITY', 43200),
        'max_receive_count' => (int) env('DPLY_QUEUE_MAX_RECEIVE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Long polling
    |--------------------------------------------------------------------------
    |
    | Capped low on purpose for v1: a held request pins a PHP-FPM worker, so a
    | long wait multiplied by many concurrent drains exhausts FPM long before
    | it troubles Postgres. Even 2s is a large reduction against a worker's
    | default `--sleep=3`. Raising this meaningfully requires the dedicated
    | Octane pop pool (see the ADR), not just a bigger number here.
    |
    */

    'long_poll' => [
        'default_seconds' => (int) env('DPLY_QUEUE_LONG_POLL_DEFAULT', 2),
        'max_seconds' => (int) env('DPLY_QUEUE_LONG_POLL_MAX', 5),
        'interval_ms' => (int) env('DPLY_QUEUE_LONG_POLL_INTERVAL_MS', 250),
        // The poll interval doubles on each empty pass up to this ceiling. A
        // queue empty at 250ms is usually still empty at 500ms, so the tail of
        // a long wait should not cost the same query budget as its start.
        'max_interval_ms' => (int) env('DPLY_QUEUE_LONG_POLL_MAX_INTERVAL_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate accounting
    |--------------------------------------------------------------------------
    |
    | Polling reads draw on their own bucket, sized at `poll_multiplier` times
    | the tier's rate. An empty ReceiveMessage changes nothing and costs one
    | indexed query, so charging it against the same allowance as a push or a
    | delete lets an idle fleet spend the throughput the customer bought — the
    | budget is gone before the burst it was waiting for arrives.
    |
    | The tier's `requests_per_minute` still bounds everything that mutates the
    | queue, which is what actually drives COGS.
    |
    */

    'rate' => [
        'poll_multiplier' => (int) env('DPLY_QUEUE_POLL_MULTIPLIER', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity tiers
    |--------------------------------------------------------------------------
    |
    | A namespace is priced by the capacity it reserves, exactly as a Realtime
    | app is priced by its connection tier — see config/realtime.php, whose
    | shape this mirrors deliberately so both managed services bill through one
    | mechanism.
    |
    | NOT metered per job. Metering was considered and rejected for v1: it
    | requires exactly-once-ish accounting because the number lands on an
    | invoice, and `requests_per_minute` already caps COGS structurally (600rpm
    | is a ~26M request/month ceiling). Counters still exist, but they are
    | observational and feed the UI only. See docs/adr/managed-services-tier.md,
    | decision 6, which amends docs/adr/dply-queue.md decision 9.
    |
    | `max_queue_depth` and `requests_per_minute` are enforced at the data
    | plane; the price is what the customer buys by raising them.
    |
    */

    'tiers' => [
        'standard' => [
            'label' => 'Standard',
            'max_queue_depth' => 100_000,
            'requests_per_minute' => 600,
            'price_cents' => 900,
        ],
        'pro' => [
            'label' => 'Pro',
            'max_queue_depth' => 500_000,
            'requests_per_minute' => 1_200,
            'price_cents' => 2_900,
        ],
    ],

    // Yearly prices are not listed here: StripeBillingProvisioner derives them
    // from the monthly amount with the standard annual discount, the same way
    // Realtime's tiers do. One discount policy, one place to change it.

    'default_tier' => env('DPLY_QUEUE_DEFAULT_TIER', 'standard'),

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | A namespace is free when it serves a dply Serverless site and billed
    | otherwise — Serverless is the product Queue exists to unblock, and
    | charging for that namespace would re-erect the barrier the product
    | removes. Cloud, BYO and Edge customers have working alternatives, so for
    | them this is a convenience purchase and bills from namespace #1.
    |
    | Billability is derived LIVE from the namespace's attached site, never
    | stamped at creation: the rule is about what a queue currently serves, not
    | how its row came to exist. A site-less namespace (an external Laravel app)
    | is billable. See docs/adr/managed-services-tier.md, decisions 4 and 5.
    |
    | `enabled` is the master safety and stays false until the predicate, the
    | Stripe tier prices and the flip notification are all in place — and then
    | through the free beta. ServerlessQueueProvisioner already auto-creates
    | namespaces the moment `surface.queue` opens, so flipping this early
    | charges Serverless customers for what they were told was included.
    |
    */

    'billing' => [
        'enabled' => filter_var(false, FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Defaults overlaid with a per-plan override. A limit of 0 means unlimited
    | — the fail-open convention from ServerLogEntitlement: nothing is enforced
    | until a number is deliberately set.
    |
    | Capacity (depth, rate) lives on the TIER, not the plan: under per-resource
    | pricing the price is the limiter, which is why config/realtime.php carries
    | no per-plan app cap either. What remains here is whether the org may use
    | the product at all, how many namespaces it may hold, and the payload
    | ceiling that protects the store from any single push.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Managed worker fleets
    |--------------------------------------------------------------------------
    |
    | dply-owned workers that drain a namespace's queue. See
    | docs/adr/managed-queue-workers.md.
    |
    | `runtime` names the substrate. It defaults to `fake` deliberately: a real
    | runtime starts containers running customer code on dply's machines, and
    | that has to be a decision someone made, not a default they inherited.
    |
    | `target_drain_seconds` is the single scaling dial — how fast a visible
    | backlog should be absorbed. Everything else the autoscaler needs it
    | measures for itself.
    |
    */

    'fleets' => [
        'runtime' => env('DPLY_QUEUE_FLEET_RUNTIME', 'fake'),
        'target_drain_seconds' => (int) env('DPLY_QUEUE_FLEET_TARGET_DRAIN', 20),

        /*
         * Rates, in millicents, for the two metered quantities. Millicents
         * because a MiB-second priced in whole cents is either zero or
         * absurd; the rollup stores raw quantities and the price is applied
         * on read, so a rate change never rewrites history.
         *
         * `pro_multiplier` is the premium a Pro worker carries over the
         * equivalent Flex size, kept as one number rather than a second rate
         * so the two classes cannot drift apart by accident.
         */
        'pricing' => [
            'flex_millicents_per_mib_second' => (float) env('DPLY_QUEUE_FLEX_RATE', 0.0000594),
            'pro_multiplier' => (float) env('DPLY_QUEUE_PRO_MULTIPLIER', 1.20),
            'millicents_per_million_operations' => (float) env('DPLY_QUEUE_OPS_RATE', 100_000),
        ],
    ],

    'entitlements' => [
        'defaults' => [
            'available' => true,
            'tier' => 'standard',
            'max_namespaces' => 1,
            'max_payload_bytes' => 262_144,
        ],

        'plans' => [
            'free' => [
                'max_namespaces' => 1,
            ],
            'starter' => [
                'max_namespaces' => 2,
            ],
            'pro' => [
                'max_namespaces' => 10,
            ],
            'business' => [
                'max_namespaces' => 0,
            ],
        ],
    ],

];
