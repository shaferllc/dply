<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Stalled-task sweeper
    |--------------------------------------------------------------------------
    |
    | Thresholds for SweepStalledTasksCommand, the backstop that fails tasks
    | stuck in `running` (lost callback / dead box). A task is swept when it has
    | gone `heartbeat_seconds` with no output, or run past its own timeout plus
    | `grace_seconds`.
    |
    */
    'stall' => [
        'heartbeat_seconds' => (int) env('DPLY_TASK_STALL_HEARTBEAT_SECONDS', 600),
        'grace_seconds' => (int) env('DPLY_TASK_STALL_GRACE_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    |
    | The directory where temporary files will be stored during task execution.
    | If not set, the system's default temporary directory will be used.
    |
    */
    'temporary_directory' => env('TASK_RUNNER_TEMPORARY_DIRECTORY', storage_path('app/task-runner/temp')),

    /*
    |--------------------------------------------------------------------------
    | EOF String
    |--------------------------------------------------------------------------
    |
    | The end-of-file string used for bash here documents. If not set,
    | a hash-based string will be generated automatically.
    |
    */
    'eof' => env('TASK_RUNNER_EOF', ''),

    /*
    |--------------------------------------------------------------------------
    | Default Timeout
    |--------------------------------------------------------------------------
    |
    | The default timeout in seconds for task execution. Set to 0 for no timeout.
    |
    */
    'default_timeout' => env('TASK_RUNNER_DEFAULT_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Task Views Path
    |--------------------------------------------------------------------------
    |
    | The path prefix for task view templates.
    |
    */
    'task_views' => env('TASK_RUNNER_TASK_VIEWS'),

    /*
    |--------------------------------------------------------------------------
    | SSH Configuration
    |--------------------------------------------------------------------------
    |
    | SSH key paths for testing environments.
    |
    */
    'test_ssh_container_public_key' => env('TASK_RUNNER_TEST_SSH_CONTAINER_PUBLIC_KEY', ''),
    'test_ssh_container_key' => env('TASK_RUNNER_TEST_SSH_CONTAINER_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Persistent Fake Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for persistent fake tasks during testing.
    |
    */
    'persistent_fake' => [
        'enabled' => env('TASK_RUNNER_PERSISTENT_FAKE_ENABLED', false),
        'storage_root' => env('TASK_RUNNER_PERSISTENT_FAKE_STORAGE_ROOT', storage_path('app/task-runner/fake')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration options.
    |
    */
    'security' => [
        'max_script_size' => env('TASK_RUNNER_MAX_SCRIPT_SIZE', 1024 * 1024), // 1MB
        'allowed_commands' => env('TASK_RUNNER_ALLOWED_COMMANDS', []), // Empty array means all commands allowed
        // Forbidden command list. Substring-with-word-boundary matching
        // (see Task::validateScript): each entry matches when bracketed
        // by start/whitespace AND not followed by another word/path
        // char. This works cleanly for atomic commands ('mkfs', 'fdisk',
        // 'parted') and for trailing-slash terminals like 'rm -rf /'.
        //
        // What it does NOT handle: patterns that need to match a
        // PREFIX with arbitrary destructive suffix (e.g., 'dd if=/dev/
        // zero of=/dev/sda' — destructive — vs 'dd if=/dev/zero of=
        // /swapfile' — legitimate swap creation). Encoding both the
        // "must end here" and "must match more" rules with one regex
        // scheme is awkward, so 'dd if=/dev/zero' is intentionally
        // omitted: the destructive disk operations it would catch are
        // already covered by mkfs / fdisk / parted, our task runner
        // generates the bash itself (untrusted input doesn't flow
        // through), and adding a per-pattern regex format here would
        // be a bigger change than the threat model warrants.
        'forbidden_commands' => env('TASK_RUNNER_FORBIDDEN_COMMANDS', [
            'rm -rf /',
            'mkfs',
            'fdisk',
            'parted',
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Retry settings for failed tasks.
    |
    */
    'retry' => [
        'enabled' => env('TASK_RUNNER_RETRY_ENABLED', true),
        'max_attempts' => env('TASK_RUNNER_MAX_ATTEMPTS', 3),
        'backoff_multiplier' => env('TASK_RUNNER_BACKOFF_MULTIPLIER', 2),
        'initial_delay' => env('TASK_RUNNER_INITIAL_DELAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Logging settings for task execution.
    |
    */
    'logging' => [
        'enabled' => env('TASK_RUNNER_LOGGING_ENABLED', true),
        'level' => env('TASK_RUNNER_LOG_LEVEL', 'info'),
        'channel' => env('TASK_RUNNER_LOG_CHANNEL', 'stack'),
        'include_output' => env('TASK_RUNNER_LOG_INCLUDE_OUTPUT', false),
        /** When a process exits non-zero or times out, log stdout/stderr (truncated) for diagnosis. */
        'include_output_on_failure' => env('TASK_RUNNER_LOG_INCLUDE_OUTPUT_ON_FAILURE', true),
        'failure_output_max_bytes' => env('TASK_RUNNER_LOG_FAILURE_OUTPUT_MAX_BYTES', 8192),

        /*
        |--------------------------------------------------------------------------
        | Streaming Logging Configuration
        |--------------------------------------------------------------------------
        |
        | Real-time streaming logging settings for live output monitoring.
        |
        */
        'streaming' => [
            'enabled' => env('TASK_RUNNER_STREAMING_ENABLED', true),
            'default_level' => env('TASK_RUNNER_STREAMING_DEFAULT_LEVEL', 'info'),
            'levels' => env('TASK_RUNNER_STREAMING_LEVELS', ['info', 'warning', 'error']),
            'channels' => [
                'process_output' => env('TASK_RUNNER_STREAMING_PROCESS_OUTPUT', true),
                'task_events' => env('TASK_RUNNER_STREAMING_TASK_EVENTS', true),
                'errors' => env('TASK_RUNNER_STREAMING_ERRORS', true),
                'progress' => env('TASK_RUNNER_STREAMING_PROGRESS', true),
            ],
            'handlers' => [
                'console' => env('TASK_RUNNER_STREAMING_CONSOLE_HANDLER', true),
                'websocket' => env('TASK_RUNNER_STREAMING_WEBSOCKET_HANDLER', false),
                'file' => env('TASK_RUNNER_STREAMING_FILE_HANDLER', false),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | View Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for task view rendering and caching.
    |
    */
    'view' => [
        'cache' => [
            'enabled' => env('TASK_RUNNER_VIEW_CACHE_ENABLED', true),
            'ttl' => env('TASK_RUNNER_VIEW_CACHE_TTL', 3600), // 1 hour
        ],

        'composers' => [
            // Global view composers
            // 'tasks.*' => function($view, $task) {
            //     $view->with('global_data', 'value');
            // },
        ],

        'validation' => [
            'check_unescaped_variables' => env('TASK_RUNNER_VIEW_VALIDATION_UNESCAPED', true),
            'check_dangerous_patterns' => env('TASK_RUNNER_VIEW_VALIDATION_PATTERNS', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SSH Connection Multiplexing
    |--------------------------------------------------------------------------
    |
    | When enabled, RemoteProcessRunner reuses a persistent OpenSSH control
    | master per server so repeated ssh/scp calls skip the TCP + auth handshake
    | (the dominant cost of a small remote op like reading a config file).
    |
    | OFF by default: the master is established out-of-band and command ssh/scp
    | attach with ControlMaster=no (so a command can never become a persistent
    | master — that would leak its pipes into the daemon and hang Symfony
    | Process at max_execution_time). The failure mode is graceful (commands
    | fall back to direct connections), but the master path touches every
    | remote call, so opt in deliberately and verify on your host first.
    |
    | 'ssh_control_persist' is passed to OpenSSH's ControlPersist — how long an
    | idle master lingers after the last connection closes.
    |
    */
    'ssh_multiplexing' => (bool) env('TASK_RUNNER_SSH_MULTIPLEXING', false),
    'ssh_control_persist' => env('TASK_RUNNER_SSH_CONTROL_PERSIST', '60s'),
];
