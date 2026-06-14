<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Intake types
    |--------------------------------------------------------------------------
    |
    | The user picks one when opening the global feedback sidebar. The `bug`
    | type reveals the severity selector and is the one that carries the most
    | auto-captured context (URL, console errors, screenshot).
    |
    */
    'types' => [
        'bug' => 'Bug',
        'idea' => 'Idea',
        'question' => 'Question',
    ],

    /*
    |--------------------------------------------------------------------------
    | Triage lifecycle (admin)
    |--------------------------------------------------------------------------
    */
    'statuses' => [
        'new' => 'New',
        'triaged' => 'Triaged',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'wont_fix' => "Won't fix",
        'duplicate' => 'Duplicate',
    ],

    'severities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'critical' => 'Critical',
    ],

    /*
    |--------------------------------------------------------------------------
    | Abuse guardrails
    |--------------------------------------------------------------------------
    |
    | Per-user rate limit (authenticated intake) plus hard payload caps that are
    | enforced server-side regardless of what the client sends.
    |
    */
    'rate_limit' => [
        'max_attempts' => (int) env('FEEDBACK_MAX_ATTEMPTS', 10),
        'decay_seconds' => (int) env('FEEDBACK_DECAY_SECONDS', 3600),
    ],

    'limits' => [
        'title_max' => 200,
        'description_min' => 10,
        'description_max' => 5000,
        // Decoded screenshot byte ceiling (~2MB) before we refuse to store it.
        'screenshot_max_bytes' => (int) env('FEEDBACK_SCREENSHOT_MAX_BYTES', 2_097_152),
        // Console ring buffer caps mirrored client + server side.
        'console_max_entries' => 50,
        'console_max_bytes' => 32_768,
        // Manual attachments.
        'attachments_max' => 3,
        'attachment_max_kb' => 4096,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Screenshots + attachments live on a dedicated PRIVATE operator disk — never
    | customer-facing storage — served to admins only through a signed/authorized
    | proxy route.
    |
    */
    'disk' => env('FEEDBACK_DISK', 'feedback'),

    /*
    |--------------------------------------------------------------------------
    | Attachment retention
    |--------------------------------------------------------------------------
    |
    | Report rows are kept forever; their stored bytes (screenshot + attachments)
    | are pruned this many days after the report reaches a terminal status.
    |
    */
    'attachment_retention_days' => (int) env('FEEDBACK_ATTACHMENT_RETENTION_DAYS', 90),

];
