<?php

namespace App\Models;

use Database\Factories\BackupConfigurationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $created_by_user_id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed> $config
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Organization $organization
 * @property-read ?User $createdByUser
 */
class BackupConfiguration extends Model
{
    /** @use HasFactory<BackupConfigurationFactory> */
    use HasFactory, HasUlids;

    public const PROVIDER_DROPBOX = 'dropbox';

    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';

    public const PROVIDER_AWS_S3 = 'aws_s3';

    public const PROVIDER_CUSTOM_S3 = 'custom_s3';

    public const PROVIDER_DIGITALOCEAN_SPACES = 'digitalocean_spaces';

    public const PROVIDER_SFTP = 'sftp';

    public const PROVIDER_FTP = 'ftp';

    public const PROVIDER_RCLONE = 'rclone';

    /** @return list<string> */
    public static function providers(): array
    {
        return [
            self::PROVIDER_DROPBOX,
            self::PROVIDER_GOOGLE_DRIVE,
            self::PROVIDER_AWS_S3,
            self::PROVIDER_CUSTOM_S3,
            self::PROVIDER_DIGITALOCEAN_SPACES,
            self::PROVIDER_SFTP,
            self::PROVIDER_FTP,
            self::PROVIDER_RCLONE,
        ];
    }

    /**
     * Providers that are fully supported today — a destination here can be
     * created, written to, downloaded from, and pruned. The rest are surfaced
     * as "coming soon" so the picker advertises the roadmap without accepting a
     * choice that would silently never receive a backup.
     *
     * Three transports back these. S3-compatible destinations upload via a
     * presigned PUT. SFTP/FTP/Rclone can't be presigned, so a client binary runs
     * on the server ({@see \App\Modules\Backups\Services\FileTransportCommandFactory}).
     * Dropbox and Google Drive are HTTPS APIs needing a bearer token, minted in
     * the control plane ({@see \App\Modules\Backups\Services\CloudApiTokenResolver})
     * and spent by a script on the server ({@see \App\Modules\Backups\Services\CloudApiCommandFactory}).
     *
     * Exposed as a constant as well so callers that need this list at
     * compile time (e.g. a class constant in a Livewire component) can derive
     * from it instead of restating it and drifting.
     *
     * @var list<string>
     */
    public const AVAILABLE_PROVIDERS = [
        self::PROVIDER_AWS_S3,
        self::PROVIDER_CUSTOM_S3,
        self::PROVIDER_DIGITALOCEAN_SPACES,
        self::PROVIDER_SFTP,
        self::PROVIDER_FTP,
        self::PROVIDER_RCLONE,
        self::PROVIDER_DROPBOX,
        self::PROVIDER_GOOGLE_DRIVE,
    ];

    /** @return list<string> */
    public static function availableProviders(): array
    {
        return self::AVAILABLE_PROVIDERS;
    }

    public static function isProviderAvailable(string $provider): bool
    {
        return in_array($provider, self::availableProviders(), true);
    }

    public static function labelForProvider(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_DROPBOX => 'Dropbox',
            self::PROVIDER_GOOGLE_DRIVE => 'Google Drive',
            self::PROVIDER_AWS_S3 => 'AWS S3',
            self::PROVIDER_CUSTOM_S3 => 'Custom S3',
            self::PROVIDER_DIGITALOCEAN_SPACES => 'DigitalOcean Spaces',
            self::PROVIDER_SFTP => 'SFTP',
            self::PROVIDER_FTP => 'FTP',
            self::PROVIDER_RCLONE => 'Rclone',
            default => $provider,
        };
    }

    /**
     * A one-line, non-secret description of where this destination points —
     * bucket, folder, or host. Safe to render in a list: it deliberately reads
     * only location fields, never a key, password, or token.
     */
    public function locationSummary(): string
    {
        $config = $this->config ?? [];
        $part = static fn (string $key): string => trim((string) ($config[$key] ?? ''));

        return match ($this->provider) {
            self::PROVIDER_AWS_S3,
            self::PROVIDER_CUSTOM_S3,
            self::PROVIDER_DIGITALOCEAN_SPACES => collect([$part('bucket'), $part('region')])
                ->filter()->implode(' · ') ?: __('no bucket set'),
            self::PROVIDER_SFTP,
            self::PROVIDER_FTP => collect([$part('host'), $part('path')])
                ->filter()->implode(' · ') ?: __('no host set'),
            self::PROVIDER_RCLONE => $part('remote_name') !== ''
                ? $part('remote_name').':'
                : __('no remote set'),
            self::PROVIDER_DROPBOX => $part('path') !== '' ? $part('path') : __('app folder root'),
            self::PROVIDER_GOOGLE_DRIVE => $part('folder_id') !== ''
                ? __('folder :id', ['id' => $part('folder_id')])
                : __('My Drive'),
            default => '',
        };
    }

    /**
     * Whether this destination's credentials survive unattended use. A Dropbox
     * access token expires in hours, so a schedule pointed at one stops working
     * without ever reporting a configuration problem — worth surfacing.
     */
    public function hasDurableCredentials(): bool
    {
        if ($this->provider !== self::PROVIDER_DROPBOX) {
            return true;
        }

        $config = $this->config ?? [];

        return trim((string) ($config['refresh_token'] ?? '')) !== ''
            && trim((string) ($config['app_key'] ?? '')) !== ''
            && trim((string) ($config['app_secret'] ?? '')) !== '';
    }

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'name',
        'provider',
        'config',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
