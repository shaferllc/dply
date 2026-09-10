<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SftpAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A file-transfer-only Linux account. See the create_sftp_accounts_table migration
 * for why the password is absent and why access is granted by ACL.
 *
 * @property string $id
 * @property string $server_id
 * @property ?string $site_id
 * @property string $username
 * @property string $source
 * @property string $home_path
 * @property string $status
 * @property ?string $last_error
 * @property ?string $created_by_user_id
 * @property ?Carbon $provisioned_at
 * @property ?array<string, mixed> $meta
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SftpAccount extends Model
{
    /** @use HasFactory<SftpAccountFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    /** Supplementary group the sshd Match block keys off. */
    public const GROUP = 'dply-sftp';

    /** dply ran useradd; removal deletes the Linux account. */
    public const SOURCE_CREATED = 'created';

    /** Pre-existing account dply only added to the group; removal leaves it. */
    public const SOURCE_ADOPTED = 'adopted';

    protected $table = 'sftp_accounts';

    protected $fillable = [
        'server_id',
        'site_id',
        'username',
        'source',
        'home_path',
        'status',
        'last_error',
        'created_by_user_id',
        'provisioned_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isAdopted(): bool
    {
        return $this->source === self::SOURCE_ADOPTED;
    }

    public function isServerScoped(): bool
    {
        return $this->site_id === null;
    }

    /**
     * A password the customer pastes into an SFTP client once. Alphanumeric on
     * purpose: it travels through a shell heredoc and a chpasswd pipe, and it
     * gets hand-copied often enough that quoting-safe beats symbol variety.
     * 24 chars of base62 is ~143 bits, well past anything punctuation would add.
     */
    public static function generatePassword(): string
    {
        return Str::password(24, letters: true, numbers: true, symbols: false, spaces: false);
    }
}
