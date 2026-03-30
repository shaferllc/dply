<?php

namespace App\Livewire\Settings;

use App\Models\BackupConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class BackupConfigurations extends Component
{
    /** @var array<string, mixed> */
    public array $createForm = [];

    /** @var array<string, mixed> */
    public array $editForm = [];

    public ?int $editing_id = null;

    public string $search = '';

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', BackupConfiguration::class);
        $this->createForm = $this->emptyForm();
        $this->editForm = $this->emptyForm();
    }

    /** @return array<string, mixed> */
    protected function emptyForm(): array
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
            'local' => [
                'path' => '',
            ],
            'rclone' => [
                'remote_name' => '',
                'config' => '',
            ],
        ];
    }

    public function createConfiguration(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();
        $this->validate($this->rulesForForm($this->createForm['provider'] ?? '', 'createForm'));
        $this->validateProviderExtras('createForm');

        $config = $this->extractConfigFromForm($this->createForm);

        Auth::user()->backupConfigurations()->create([
            'name' => $this->createForm['name'],
            'provider' => $this->createForm['provider'],
            'config' => $config,
        ]);

        $this->createForm = $this->emptyForm();
        $this->flash_success = __('Backup configuration saved.');
        $this->flash_error = null;
    }

    public function startEdit(int $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('update', $config);

        $this->editing_id = $config->id;
        $this->editForm = $this->emptyForm();
        $this->editForm['name'] = $config->name;
        $this->editForm['provider'] = $config->provider;
        $this->hydrateFormFromConfig($this->editForm, $config->provider, $config->config);
    }

    public function cancelEdit(): void
    {
        $this->editing_id = null;
        $this->editForm = $this->emptyForm();
    }

    public function updateConfiguration(): void
    {
        if ($this->editing_id === null) {
            return;
        }

        $model = BackupConfiguration::query()->findOrFail($this->editing_id);

        $this->authorize('update', $model);

        $this->resetErrorBag();
        $this->validate($this->rulesForForm($this->editForm['provider'] ?? '', 'editForm'));
        $this->validateProviderExtras('editForm');

        $model->update([
            'name' => $this->editForm['name'],
            'provider' => $this->editForm['provider'],
            'config' => $this->extractConfigFromForm($this->editForm),
        ]);

        $this->cancelEdit();
        $this->flash_success = __('Backup configuration updated.');
        $this->flash_error = null;
    }

    public function deleteConfiguration(int $id): void
    {
        $config = BackupConfiguration::query()->findOrFail($id);

        $this->authorize('delete', $config);

        $config->delete();

        if ($this->editing_id === $id) {
            $this->cancelEdit();
        }

        $this->flash_success = __('Backup configuration removed.');
        $this->flash_error = null;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    protected function validateProviderExtras(string $prefix): void
    {
        $provider = $prefix === 'createForm'
            ? ($this->createForm['provider'] ?? '')
            : ($this->editForm['provider'] ?? '');

        if ($provider !== BackupConfiguration::PROVIDER_SFTP) {
            return;
        }

        $sftp = $prefix === 'createForm' ? $this->createForm['sftp'] : $this->editForm['sftp'];
        $password = trim((string) ($sftp['password'] ?? ''));
        $privateKey = trim((string) ($sftp['private_key'] ?? ''));

        if ($password === '' && $privateKey === '') {
            $this->addError($prefix.'.sftp.password', __('Provide a password or paste a private key.'));
        }
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function extractConfigFromForm(array $form): array
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
                'port' => $this->normalizePort($form['sftp']['port'] ?? null, 22),
                'username' => $form['sftp']['username'],
                'password' => $form['sftp']['password'] ?? '',
                'path' => $form['sftp']['path'] ?? '',
                'private_key' => $form['sftp']['private_key'] ?? '',
            ],
            BackupConfiguration::PROVIDER_FTP => [
                'host' => $form['ftp']['host'],
                'port' => $this->normalizePort($form['ftp']['port'] ?? null, 21),
                'username' => $form['ftp']['username'],
                'password' => $form['ftp']['password'],
                'path' => $form['ftp']['path'] ?? '',
            ],
            BackupConfiguration::PROVIDER_LOCAL => [
                'path' => $form['local']['path'],
            ],
            BackupConfiguration::PROVIDER_RCLONE => [
                'remote_name' => $form['rclone']['remote_name'],
                'config' => $form['rclone']['config'] ?? '',
            ],
            default => [],
        };
    }

    protected function normalizePort(mixed $port, int $default): int
    {
        if ($port === null || $port === '') {
            return $default;
        }

        return max(1, min(65535, (int) $port));
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $config
     */
    protected function hydrateFormFromConfig(array &$form, string $provider, array $config): void
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
            BackupConfiguration::PROVIDER_LOCAL => $form['local'] = array_merge($form['local'], [
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
     * @return array<string, mixed>
     */
    protected function rulesForForm(string $provider, string $prefix): array
    {
        $nameKey = $prefix.'.name';
        $providerKey = $prefix.'.provider';

        $base = [
            $nameKey => ['required', 'string', 'max:160'],
            $providerKey => ['required', 'string', Rule::in(BackupConfiguration::providers())],
        ];

        return array_merge($base, match ($provider) {
            BackupConfiguration::PROVIDER_CUSTOM_S3,
            BackupConfiguration::PROVIDER_AWS_S3,
            BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES => [
                $prefix.'.s3.access_key' => ['required', 'string', 'max:500'],
                $prefix.'.s3.secret' => ['required', 'string', 'max:4000'],
                $prefix.'.s3.bucket' => ['required', 'string', 'max:255'],
                $prefix.'.s3.region' => ['nullable', 'string', 'max:100'],
                $prefix.'.s3.endpoint' => $provider === BackupConfiguration::PROVIDER_AWS_S3
                    ? ['nullable', 'string', 'max:500']
                    : ['required', 'string', 'max:500'],
                $prefix.'.s3.use_path_style' => ['boolean'],
            ],
            BackupConfiguration::PROVIDER_DROPBOX => [
                $prefix.'.dropbox.access_token' => ['required', 'string', 'max:4000'],
            ],
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => [
                $prefix.'.google.client_id' => ['nullable', 'string', 'max:500'],
                $prefix.'.google.client_secret' => ['nullable', 'string', 'max:500'],
                $prefix.'.google.refresh_token' => ['required', 'string', 'max:4000'],
            ],
            BackupConfiguration::PROVIDER_SFTP => [
                $prefix.'.sftp.host' => ['required', 'string', 'max:255'],
                $prefix.'.sftp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                $prefix.'.sftp.username' => ['required', 'string', 'max:255'],
                $prefix.'.sftp.password' => ['nullable', 'string', 'max:4000'],
                $prefix.'.sftp.path' => ['nullable', 'string', 'max:500'],
                $prefix.'.sftp.private_key' => ['nullable', 'string', 'max:16000'],
            ],
            BackupConfiguration::PROVIDER_FTP => [
                $prefix.'.ftp.host' => ['required', 'string', 'max:255'],
                $prefix.'.ftp.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                $prefix.'.ftp.username' => ['required', 'string', 'max:255'],
                $prefix.'.ftp.password' => ['required', 'string', 'max:4000'],
                $prefix.'.ftp.path' => ['nullable', 'string', 'max:500'],
            ],
            BackupConfiguration::PROVIDER_LOCAL => [
                $prefix.'.local.path' => ['required', 'string', 'max:2000'],
            ],
            BackupConfiguration::PROVIDER_RCLONE => [
                $prefix.'.rclone.remote_name' => ['required', 'string', 'max:255'],
                $prefix.'.rclone.config' => ['nullable', 'string', 'max:32000'],
            ],
            default => [],
        });
    }

    public function render(): View
    {
        $query = Auth::user()
            ->backupConfigurations()
            ->orderBy('name');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return view('livewire.settings.backup-configurations', [
            'configurations' => $query->get(),
        ]);
    }
}
