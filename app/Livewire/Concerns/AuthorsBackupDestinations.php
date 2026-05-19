<?php

namespace App\Livewire\Concerns;

use App\Models\BackupConfiguration;
use Illuminate\Validation\Rule;

/**
 * Form helpers for create/edit of a {@see BackupConfiguration} ("backup
 * destination"). Used by both `Livewire\Settings\BackupConfigurations` and
 * `Livewire\Servers\WorkspaceBackups` so the provider picker, per-provider
 * field set, validation, and persistence shape stay identical across both
 * surfaces. Drift here would let an operator create a destination via one
 * surface that the other later refuses to edit.
 *
 * Each method is parameterized on the form-property name (`createForm`,
 * `editForm`, `destinationForm`, etc.) so the host component can keep its own
 * state without colliding with another instance of the trait.
 */
trait AuthorsBackupDestinations
{
    /**
     * Initial state for a fresh destination form. Provider defaults to
     * Custom S3 because that's the catch-all for B2/R2/Wasabi/MinIO/DO
     * Spaces — the most common path for operator-owned object storage.
     *
     * @return array<string, mixed>
     */
    protected function emptyDestinationForm(): array
    {
        return [
            'name' => '',
            'provider' => BackupConfiguration::PROVIDER_CUSTOM_S3,
            's3' => [
                'access_key' => '',
                'secret' => '',
                'bucket' => '',
                'region' => '',
                'endpoint' => '',
                'use_path_style' => false,
            ],
            'dropbox' => [
                'access_token' => '',
            ],
            'google' => [
                'client_id' => '',
                'client_secret' => '',
                'refresh_token' => '',
            ],
            'sftp' => [
                'host' => '',
                'port' => '22',
                'username' => '',
                'password' => '',
                'path' => '',
                'private_key' => '',
            ],
            'ftp' => [
                'host' => '',
                'port' => '21',
                'username' => '',
                'password' => '',
                'path' => '',
            ],
            'rclone' => [
                'remote_name' => '',
                'config' => '',
            ],
        ];
    }

    /**
     * Cross-field validation that doesn't fit the rule-array shape (SFTP needs
     * either a password or a private key — `required_without` would force both
     * inputs to render as required, which is wrong from the user's perspective).
     *
     * @param  array<string, mixed>  $form
     */
    protected function validateDestinationFormExtras(string $formProperty, array $form): void
    {
        $provider = $form['provider'] ?? '';
        if ($provider !== BackupConfiguration::PROVIDER_SFTP) {
            return;
        }

        $sftp = $form['sftp'] ?? [];
        $password = trim((string) ($sftp['password'] ?? ''));
        $privateKey = trim((string) ($sftp['private_key'] ?? ''));

        if ($password === '' && $privateKey === '') {
            $this->addError($formProperty.'.sftp.password', __('Provide a password or paste a private key.'));
        }
    }

    /**
     * Pulls the per-provider sub-array out of $form and reshapes it into the
     * persisted `config` payload (the column is `encrypted:array`).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function extractDestinationConfig(array $form): array
    {
        return match ($form['provider']) {
            BackupConfiguration::PROVIDER_CUSTOM_S3,
            BackupConfiguration::PROVIDER_AWS_S3,
            BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES => [
                'access_key' => $form['s3']['access_key'],
                'secret' => $form['s3']['secret'],
                'bucket' => $form['s3']['bucket'],
                'region' => $form['s3']['region'] ?? '',
                'endpoint' => $form['s3']['endpoint'] ?? '',
                'use_path_style' => (bool) ($form['s3']['use_path_style'] ?? false),
            ],
            BackupConfiguration::PROVIDER_DROPBOX => [
                'access_token' => $form['dropbox']['access_token'],
            ],
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => [
                'client_id' => $form['google']['client_id'] ?? '',
                'client_secret' => $form['google']['client_secret'] ?? '',
                'refresh_token' => $form['google']['refresh_token'],
            ],
            BackupConfiguration::PROVIDER_SFTP => [
                'host' => $form['sftp']['host'],
                'port' => $this->normalizeDestinationPort($form['sftp']['port'] ?? null, 22),
                'username' => $form['sftp']['username'],
                'password' => $form['sftp']['password'] ?? '',
                'path' => $form['sftp']['path'] ?? '',
                'private_key' => $form['sftp']['private_key'] ?? '',
            ],
            BackupConfiguration::PROVIDER_FTP => [
                'host' => $form['ftp']['host'],
                'port' => $this->normalizeDestinationPort($form['ftp']['port'] ?? null, 21),
                'username' => $form['ftp']['username'],
                'password' => $form['ftp']['password'],
                'path' => $form['ftp']['path'] ?? '',
            ],
            BackupConfiguration::PROVIDER_RCLONE => [
                'remote_name' => $form['rclone']['remote_name'],
                'config' => $form['rclone']['config'] ?? '',
            ],
            default => [],
        };
    }

    /**
     * Hydrate $form back from a persisted config payload. Used when starting
     * an edit of an existing destination.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $config
     */
    protected function hydrateDestinationFormFromConfig(array &$form, string $provider, array $config): void
    {
        match ($provider) {
            BackupConfiguration::PROVIDER_CUSTOM_S3,
            BackupConfiguration::PROVIDER_AWS_S3,
            BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES => $form['s3'] = array_merge($form['s3'], [
                'access_key' => $config['access_key'] ?? '',
                'secret' => $config['secret'] ?? '',
                'bucket' => $config['bucket'] ?? '',
                'region' => $config['region'] ?? '',
                'endpoint' => $config['endpoint'] ?? '',
                'use_path_style' => (bool) ($config['use_path_style'] ?? false),
            ]),
            BackupConfiguration::PROVIDER_DROPBOX => $form['dropbox'] = array_merge($form['dropbox'], [
                'access_token' => $config['access_token'] ?? '',
            ]),
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => $form['google'] = array_merge($form['google'], [
                'client_id' => $config['client_id'] ?? '',
                'client_secret' => $config['client_secret'] ?? '',
                'refresh_token' => $config['refresh_token'] ?? '',
            ]),
            BackupConfiguration::PROVIDER_SFTP => $form['sftp'] = array_merge($form['sftp'], [
                'host' => $config['host'] ?? '',
                'port' => (string) ($config['port'] ?? 22),
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'path' => $config['path'] ?? '',
                'private_key' => $config['private_key'] ?? '',
            ]),
            BackupConfiguration::PROVIDER_FTP => $form['ftp'] = array_merge($form['ftp'], [
                'host' => $config['host'] ?? '',
                'port' => (string) ($config['port'] ?? 21),
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'path' => $config['path'] ?? '',
            ]),
            BackupConfiguration::PROVIDER_RCLONE => $form['rclone'] = array_merge($form['rclone'], [
                'remote_name' => $config['remote_name'] ?? '',
                'config' => $config['config'] ?? '',
            ]),
            default => null,
        };
    }

    /**
     * Validation rule set keyed by `$formProperty.<field>`, so callers can pass
     * the resulting array straight to `$this->validate(...)`.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function destinationFormRules(string $formProperty, string $provider): array
    {
        $nameKey = $formProperty.'.name';
        $providerKey = $formProperty.'.provider';

        $base = [
            $nameKey => ['required', 'string', 'max:160'],
            $providerKey => ['required', 'string', Rule::in(BackupConfiguration::providers())],
        ];

        return array_merge($base, match ($provider) {
            BackupConfiguration::PROVIDER_CUSTOM_S3,
            BackupConfiguration::PROVIDER_AWS_S3,
            BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES => [
                $formProperty.'.s3.access_key' => ['required', 'string', 'max:500'],
                $formProperty.'.s3.secret' => ['required', 'string', 'max:4000'],
                $formProperty.'.s3.bucket' => ['required', 'string', 'max:255'],
                $formProperty.'.s3.region' => ['nullable', 'string', 'max:100'],
                $formProperty.'.s3.endpoint' => $provider === BackupConfiguration::PROVIDER_AWS_S3
                    ? ['nullable', 'string', 'max:500']
                    : ['required', 'string', 'max:500'],
                $formProperty.'.s3.use_path_style' => ['boolean'],
            ],
            BackupConfiguration::PROVIDER_DROPBOX => [
                $formProperty.'.dropbox.access_token' => ['required', 'string', 'max:4000'],
            ],
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => [
                $formProperty.'.google.client_id' => ['nullable', 'string', 'max:500'],
                $formProperty.'.google.client_secret' => ['nullable', 'string', 'max:500'],
                $formProperty.'.google.refresh_token' => ['required', 'string', 'max:4000'],
            ],
            BackupConfiguration::PROVIDER_SFTP => [
                $formProperty.'.sftp.host' => ['required', 'string', 'max:255'],
                $formProperty.'.sftp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                $formProperty.'.sftp.username' => ['required', 'string', 'max:255'],
                $formProperty.'.sftp.password' => ['nullable', 'string', 'max:4000'],
                $formProperty.'.sftp.path' => ['nullable', 'string', 'max:500'],
                $formProperty.'.sftp.private_key' => ['nullable', 'string', 'max:16000'],
            ],
            BackupConfiguration::PROVIDER_FTP => [
                $formProperty.'.ftp.host' => ['required', 'string', 'max:255'],
                $formProperty.'.ftp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                $formProperty.'.ftp.username' => ['required', 'string', 'max:255'],
                $formProperty.'.ftp.password' => ['required', 'string', 'max:4000'],
                $formProperty.'.ftp.path' => ['nullable', 'string', 'max:500'],
            ],
            BackupConfiguration::PROVIDER_RCLONE => [
                $formProperty.'.rclone.remote_name' => ['required', 'string', 'max:255'],
                $formProperty.'.rclone.config' => ['nullable', 'string', 'max:32000'],
            ],
            default => [],
        });
    }

    private function normalizeDestinationPort(mixed $port, int $default): int
    {
        if ($port === null || $port === '') {
            return $default;
        }

        return max(1, min(65535, (int) $port));
    }
}
