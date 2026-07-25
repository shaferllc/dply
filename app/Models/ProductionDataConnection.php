<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-local-user device-flow connection to a remote (production) control plane.
 * Token is encrypted at rest; only usable when APP_ENV=local.
 *
 * @property string $id
 * @property string $user_id
 * @property string $base_url
 * @property string $api_token
 * @property ?string $remote_organization_id
 * @property ?string $remote_organization_name
 * @property ?string $remote_organization_slug
 * @property ?string $remote_user_email
 * @property ?string $remote_user_name
 * @property ?Carbon $connected_at
 * @property ?Carbon $last_used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class ProductionDataConnection extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'base_url',
        'api_token',
        'remote_organization_id',
        'remote_organization_name',
        'remote_organization_slug',
        'remote_user_email',
        'remote_user_name',
        'connected_at',
        'last_used_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hostLabel(): string
    {
        $host = parse_url($this->base_url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $this->base_url;
    }
}
