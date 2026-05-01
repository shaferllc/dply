<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fake cloud provision (local / testing only)
    |--------------------------------------------------------------------------
    |
    | When enabled, VM provision jobs skip vendor APIs and point the server at
    | a configurable SSH endpoint (e.g. docker-compose.ssh-dev.yml). Never
    | enable in production: see FakeCloudProvision::enabled().
    |
    */
    'env_flag' => (bool) env('DPLY_FAKE_CLOUD_PROVISION', false),

    'allowed_environments' => ['local', 'testing'],

    'ssh_host' => env('DPLY_FAKE_CLOUD_SSH_HOST', '127.0.0.1'),

    'ssh_port' => (int) env('DPLY_FAKE_CLOUD_SSH_PORT', 2222),

    'ssh_user' => env('DPLY_FAKE_CLOUD_SSH_USER', 'root'),

    /*
    | Optional TaskRunner remote script directory for fake servers. The bundled
    | docker/ssh-dev image runs sshd as root (HOME=/root), so the default
    | /root/.dply-task-runner is correct without override.
    */
    'ssh_script_path' => env('DPLY_FAKE_CLOUD_SSH_SCRIPT_PATH'),

    /*
    | Optional override per provider string (e.g. aws often uses "ubuntu").
    | Falls back to ssh_user above.
    */
    'ssh_user_by_provider' => [
        'aws' => env('DPLY_FAKE_CLOUD_SSH_USER_AWS'),
    ],

    /*
    | Marks fake servers so Poll*IpJobs can no-op safely.
    */
    'provider_id_sentinel' => env('DPLY_FAKE_CLOUD_PROVIDER_ID', 'fake-local'),

    /*
    | If set, used as ssh_private_key (and recovery) instead of generating keys.
    | Must match authorized_keys on the test host for SSH to succeed.
    */
    'ssh_private_key' => env('DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY'),

    /*
    | When ssh_private_key is empty, load key material from this path (relative to
    | project root unless absolute). Default points at the bundled key authorized
    | by docker-compose.ssh-dev.yml for root login.
    */
    'ssh_private_key_path' => env('DPLY_FAKE_CLOUD_SSH_PRIVATE_KEY_PATH', 'docker/ssh-dev/local_fake_cloud_ed25519'),

    /*
    | Optional: password auth for SshConnection (meta.local_runtime.ssh_password).
    */
    'ssh_password' => env('DPLY_FAKE_CLOUD_SSH_PASSWORD'),

    /*
    | When keys are generated, log recovery_public_key in local environment.
    */
    'log_generated_public_key' => (bool) env('DPLY_FAKE_CLOUD_LOG_PUBLIC_KEY', true),

    /*
    | Fly.io: no RunSetupScriptJob for Fly hosts; optional stub skips Fly API
    | and marks the server ready for journey/UI smoke only.
    */
    'fly_io_ui_stub' => (bool) env('DPLY_FAKE_CLOUD_FLY_IO_UI_STUB', false),

];
