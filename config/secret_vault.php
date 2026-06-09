<?php

declare(strict_types=1);

use App\Models\Server;

/**
 * Configuration for the SecretVault DR system (see deploy/SECRETS.md).
 *
 * The bash guards (deploy/secrets/*.sh) and this PHP service share three
 * contracts — the age recipient file, the blob key naming, and the store
 * layout — so a blob written by either side is readable by the other.
 */
return [
    // Path to the pinned `age` binary. deploy/secrets/install-cron.sh pins a
    // known version+sha under shared/secrets/bin; locally just use PATH.
    'age_bin' => env('SECRET_VAULT_AGE_BIN', 'age'),

    // Public age recipients (one per line). Encryption needs only this; it is
    // safe on every box. The matching private identity is held OFFLINE.
    'recipients_path' => env('SECRET_VAULT_RECIPIENTS_PATH', '/home/dply/shared/secrets/age-recipients.txt'),

    // Private age identity — present ONLY where a restore/drill runs (the
    // isolated drill host), never on prod web/worker boxes.
    'identity_path' => env('SECRET_VAULT_IDENTITY_PATH'),

    // Object-key prefix; bumping the version segment starts a new layout.
    'key_prefix' => env('SECRET_VAULT_KEY_PREFIX', 'secret-vault/v1'),

    'stores' => [
        // Versioned + object-locked bucket in a SEPARATE cloud account.
        // Key names mirror DatabaseBackupS3ClientFactory so the S3 wiring is identical.
        'object' => [
            'enabled' => (bool) env('SECRET_VAULT_OBJECT_ENABLED', false),
            'bucket' => env('SECRET_VAULT_OBJECT_BUCKET'),
            'region' => env('SECRET_VAULT_OBJECT_REGION', 'us-east-1'),
            'endpoint' => env('SECRET_VAULT_OBJECT_ENDPOINT'),
            'access_key' => env('SECRET_VAULT_OBJECT_ACCESS_KEY'),
            'secret' => env('SECRET_VAULT_OBJECT_SECRET'),
            'use_path_style' => (bool) env('SECRET_VAULT_OBJECT_PATH_STYLE', false),
            'path' => env('SECRET_VAULT_OBJECT_PATH', ''),
        ],
        // Private ops git repo (ciphertext only). The bash side pushes via ssh;
        // PHP shells the same `git` against a working clone.
        'git' => [
            'enabled' => (bool) env('SECRET_VAULT_GIT_ENABLED', false),
            'repo' => env('SECRET_VAULT_GIT_REPO'),
            'branch' => env('SECRET_VAULT_GIT_BRANCH', 'main'),
            'work_dir' => env('SECRET_VAULT_GIT_WORKDIR', storage_path('app/secret-vault/git')),
        ],
        // 1Password documents via the `op` CLI (emergency human access).
        'onepassword' => [
            'enabled' => (bool) env('SECRET_VAULT_OP_ENABLED', false),
            'vault' => env('SECRET_VAULT_OP_VAULT'),
            'op_bin' => env('SECRET_VAULT_OP_BIN', 'op'),
        ],
    ],

    'reencrypt' => [
        // Auto-discovered encrypted-cast models are scanned from here.
        'models_path' => app_path('Models'),
        // FQCNs to force-include / exclude beyond the auto-scan.
        'extra_models' => [],
        'exclude_models' => [],
        // encrypt()-helper columns that are NOT `encrypted` casts and thus not
        // auto-discoverable. Keep this in lockstep with the codebase — the
        // SecretReencryptCoverageTest fails if a new raw Crypt usage appears
        // without being listed here.
        'raw_crypt' => [
            ['connection' => null, 'table' => 'users', 'columns' => ['two_factor_secret', 'two_factor_recovery_codes']],
        ],
        'checkpoint_disk' => 'local',
        'checkpoint_path' => 'secret-vault/reencrypt-progress.json',
    ],

    // Dead-man's-switch ping URLs (per guard) + explicit-failure webhook.
    'dms' => [
        'escrow' => env('SECRET_VAULT_DMS_ESCROW'),
        'db' => env('SECRET_VAULT_DMS_DB'),
        'drill' => env('SECRET_VAULT_DMS_DRILL'),
    ],
    'alert_webhook' => env('SECRET_VAULT_ALERT_WEBHOOK'),

    'db' => [
        'retention_days' => (int) env('SECRET_VAULT_DB_RETENTION_DAYS', 30),
    ],

    // A known-present encrypted column used to prove APP_KEY round-trips.
    'canary' => [
        'model' => Server::class,
        'column' => 'ssh_private_key',
    ],
];
