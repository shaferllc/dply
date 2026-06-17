<?php

namespace App\Models;

use Database\Factories\BackupConfigurationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $created_by_user_id
 * @property string $name
 * @property string $provider
 * @property array $config
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
     * Providers that are fully supported today. Only the S3-compatible
     * destinations are live; the rest are surfaced as "coming soon" so the
     * picker advertises the roadmap without accepting a non-working choice.
     *
     * @return list<string>
     */
    public static function availableProviders(): array
    {
        return [
            self::PROVIDER_AWS_S3,
            self::PROVIDER_CUSTOM_S3,
            self::PROVIDER_DIGITALOCEAN_SPACES,
        ];
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
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
