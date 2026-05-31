<?php

declare(strict_types=1);

return [

    'statuses' => [
        'planned' => 'Planned',
        'in_progress' => 'In progress',
        'shipped' => 'Shipped',
    ],

    'areas' => [
        'platform' => 'Platform',
        'servers' => 'Servers',
        'edge' => 'Edge',
        'cloud' => 'Cloud',
        'serverless' => 'Serverless',
        'other' => 'Other',
    ],

    'suggestion_statuses' => [
        'new' => 'New',
        'reviewed' => 'Reviewed',
        'declined' => 'Declined',
    ],

    'suggestion_rate_limit' => [
        'max_attempts' => (int) env('ROADMAP_SUGGESTION_MAX_ATTEMPTS', 3),
        'decay_seconds' => (int) env('ROADMAP_SUGGESTION_DECAY_SECONDS', 3600),
    ],

    'suggestion_emails_enabled' => filter_var(env('ROADMAP_SUGGESTION_EMAILS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'recently_shipped_limit' => (int) env('ROADMAP_RECENTLY_SHIPPED_LIMIT', 5),

];
