<?php

/**
 * Marketing pricing content — one source for every /pricing design.
 * Plan numbers stay in config/product/subscription.php; this only shapes them.
 */
$plans = collect(config('subscription.standard.plans', []))
    ->map(fn (array $plan, string $key) => [
        'key' => $key,
        'label' => $plan['label'] ?? ucfirst($key),
        'price' => (int) ($plan['price_cents'] ?? 0) / 100,
        'servers' => $plan['max_servers'] ?? null,
        'sites' => $plan['max_sites'] ?? null,
    ])
    ->values()
    ->all();

$blurbs = [
    'free' => 'One server, forever. No card.',
    'starter' => 'A side project or two.',
    'pro' => 'A team running a real fleet.',
    'business' => 'Agencies. No server cap.',
];

return [
    'plans' => $plans,
    'blurbs' => $blurbs,
    'highlight' => 'pro',
    'annual_pct' => (int) config('subscription.standard.annual_discount_pct', 20),

    // Same on every plan — stated once instead of repeated down four cards.
    'included' => [
        'Unlimited team members',
        'Unlimited deploys and rollbacks',
        'TLS, databases, cron, workers, firewall',
        'Metrics, health checks and backups',
        'Audit log and role-based access',
        'Full API and CLI access',
    ],

    // Flag-gated add-ons; the cheapest tier is the headline.
    'addons' => [
        [
            'name' => 'Realtime',
            'flag' => 'surface.realtime',
            'price' => (collect((array) config('realtime.tiers', []))->pluck('price_cents')->filter()->min() ?: 1500) / 100,
            'unit' => 'per app / mo',
            'desc' => 'Pusher-compatible WebSocket relay. Drop-in for Echo or Reverb.',
        ],
        [
            'name' => 'Queues',
            'flag' => 'surface.queue',
            'price' => (collect((array) config('queue_service.tiers', []))->pluck('price_cents')->filter()->min() ?: 900) / 100,
            'unit' => 'per queue / mo',
            'desc' => 'Managed job queue over the built-in SQS driver. Priced by capacity, never per job.',
        ],
    ],

    'faqs' => [
        [
            'q' => 'Why price by server instead of per seat?',
            'a' => 'Our cost scales with the work done for each server — deploys, metrics, scheduler ticks, audit. Counting servers matches that honestly, so seats stay unlimited.',
        ],
        [
            'q' => 'Does this include the hardware?',
            'a' => 'No. You pay your provider for the machines; dply is the platform fee on top. A Hetzner fleet and an AWS fleet of the same size cost the same here.',
        ],
        [
            'q' => 'What happens when I outgrow a plan?',
            'a' => 'You move up automatically and Stripe prorates the difference. Servers less than a day old do not count, so throwaway test boxes are free.',
        ],
        [
            'q' => 'Can I cancel anytime?',
            'a' => 'Yes, from the Stripe billing portal. Your servers keep running at your provider — dply simply stops managing them.',
        ],
    ],
];
