<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $provider
 * @property array<string, mixed>|null $config
 * @property Carbon|null $last_ok_at
 * @property string|null $last_error
 */
class PlatformConnection extends Model
{
    use HasUlids;

    public const PROVIDER_SLACK = 'slack';

    public const PROVIDER_DISCORD = 'discord';

    public const PROVIDER_TELEGRAM = 'telegram';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_SLACK,
        self::PROVIDER_DISCORD,
        self::PROVIDER_TELEGRAM,
    ];

    protected $table = 'platform_connections';

    protected $fillable = [
        'provider',
        'config',
        'last_ok_at',
        'last_error',
    ];

    protected $casts = [
        'config' => 'encrypted:array',
        'last_ok_at' => 'datetime',
    ];

    protected $hidden = [
        'config',
    ];
}
