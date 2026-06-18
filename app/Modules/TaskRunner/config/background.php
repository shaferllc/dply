<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Background Task Tracking Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for background task tracking
    | with callback support and real-time monitoring.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | Configure how often background tasks are monitored and checked for
    | status updates, timeouts, and completion.
    |
    */
    'monitoring' => [
        // How often to check task status (in seconds)
        'interval' => env('TASK_RUNNER_MONITORING_INTERVAL', 5),

        // Maximum number of monitoring attempts before giving up
        'max_attempts' => env('TASK_RUNNER_MONITORING_MAX_ATTEMPTS', 100),

        // Whether to enable real-time monitoring
        'enabled' => env('TASK_RUNNER_MONITORING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback Settings
    |--------------------------------------------------------------------------
    |
    | Configure callback behavior for background tasks including retry logic,
    | timeouts, and delivery settings.
    |
    */
    'callbacks' => [
        // Default callback timeout in seconds
        'timeout' => env('TASK_RUNNER_CALLBACK_TIMEOUT', 30),

        // Maximum number of callback retry attempts
        'max_attempts' => env('TASK_RUNNER_CALLBACK_MAX_ATTEMPTS', 3),

        // Delay between callback retries in seconds
        'retry_delay' => env('TASK_RUNNER_CALLBACK_RETRY_DELAY', 5),

        // Exponential backoff multiplier for retries
        'backoff_multiplier' => env('TASK_RUNNER_CALLBACK_BACKOFF_MULTIPLIER', 2),

        // Whether callbacks are enabled by default
        'enabled' => env('TASK_RUNNER_CALLBACKS_ENABLED', true),

        // Default callback headers
        'headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'TaskRunner/2.0',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Configure queue settings for background task monitoring jobs.
    |
    */
    'queue' => [
        // Queue name for monitoring jobs
        'monitoring_queue' => env('TASK_RUNNER_MONITORING_QUEUE', 'task-monitoring'),

        // Queue connection to use
        'connection' => env('TASK_RUNNER_QUEUE_CONNECTION', 'default'),

        // Whether to use delayed jobs for monitoring
        'use_delayed_jobs' => env('TASK_RUNNER_USE_DELAYED_JOBS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup of old completed tasks.
    |
    */
    'cleanup' => [
        // Number of days to keep completed tasks
        'keep_days' => env('TASK_RUNNER_CLEANUP_KEEP_DAYS', 30),

        // Whether to enable automatic cleanup
        'enabled' => env('TASK_RUNNER_CLEANUP_ENABLED', true),

        // Schedule for cleanup job (cron expression)
        'schedule' => env('TASK_RUNNER_CLEANUP_SCHEDULE', '0 2 * * *'), // Daily at 2 AM
    ],

    /*
    |--------------------------------------------------------------------------
    | Progress Tracking
    |--------------------------------------------------------------------------
    |
    | Configure how task progress is calculated and reported.
    |
    */
    'progress' => [
        // Whether to enable progress tracking
        'enabled' => env('TASK_RUNNER_PROGRESS_ENABLED', true),

        // Progress calculation method: 'time_based' or 'custom'
        'calculation_method' => env('TASK_RUNNER_PROGRESS_METHOD', 'time_based'),

        // Minimum progress update interval in seconds
        'update_interval' => env('TASK_RUNNER_PROGRESS_UPDATE_INTERVAL', 10),

        // Maximum progress percentage before completion
        'max_percentage' => env('TASK_RUNNER_PROGRESS_MAX_PERCENTAGE', 95),
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming Settings
    |--------------------------------------------------------------------------
    |
    | Configure real-time streaming of task events and output.
    |
    */
    'streaming' => [
        // Whether to enable real-time streaming
        'enabled' => env('TASK_RUNNER_STREAMING_ENABLED', true),

        // Streaming channels
        'channels' => [
            'task_events' => 'task-events',
            'task_output' => 'task-output',
            'task_progress' => 'task-progress',
        ],

        // Maximum output buffer size in bytes
        'max_buffer_size' => env('TASK_RUNNER_STREAMING_BUFFER_SIZE', 1024 * 1024), // 1MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configure notification channels for task events.
    |
    */
    'notifications' => [
        // Whether to send notifications for task events
        'enabled' => env('TASK_RUNNER_NOTIFICATIONS_ENABLED', true),

        // Notification channels
        'channels' => [
            'slack' => env('TASK_RUNNER_SLACK_WEBHOOK_URL'),
            'email' => env('TASK_RUNNER_EMAIL_NOTIFICATIONS', false),
            'webhook' => env('TASK_RUNNER_WEBHOOK_URL'),
        ],

        // Events to notify about
        'events' => [
            'task_started' => true,
            'task_completed' => true,
            'task_failed' => true,
            'task_timeout' => true,
            'task_progress' => false, // Disable progress notifications to avoid spam
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Configure security settings for background task tracking.
    |
    */
    'security' => [
        // Whether to validate callback signatures
        'validate_signatures' => env('TASK_RUNNER_VALIDATE_SIGNATURES', true),

        // Secret key for callback signature validation
        'secret_key' => env('TASK_RUNNER_SECRET_KEY'),

        // Allowed callback origins (for CORS)
        'allowed_origins' => explode(',', env('TASK_RUNNER_ALLOWED_ORIGINS', '*')),

        // Rate limiting for callbacks
        'rate_limit' => [
            'enabled' => env('TASK_RUNNER_RATE_LIMIT_ENABLED', true),
            'max_attempts' => env('TASK_RUNNER_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'decay_minutes' => env('TASK_RUNNER_RATE_LIMIT_DECAY_MINUTES', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configure performance-related settings for background task tracking.
    |
    */
    'performance' => [
        // Maximum number of concurrent background tasks
        'max_concurrent_tasks' => env('TASK_RUNNER_MAX_CONCURRENT_TASKS', 50),

        // Task execution timeout in seconds
        'execution_timeout' => env('TASK_RUNNER_EXECUTION_TIMEOUT', 3600), // 1 hour

        // Memory limit for task execution
        'memory_limit' => env('TASK_RUNNER_MEMORY_LIMIT', '512M'),

        // Whether to enable task result caching
        'cache_results' => env('TASK_RUNNER_CACHE_RESULTS', true),

        // Cache TTL for task results in seconds
        'cache_ttl' => env('TASK_RUNNER_CACHE_TTL', 3600), // 1 hour
    ],
];
