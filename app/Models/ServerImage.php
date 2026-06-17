<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerProvider;
use App\Jobs\CreateServerImageJob;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * A full-disk image (snapshot) of a server captured through its cloud provider's
 * API. Lifecycle:
 *
 *   pending → creating   when {@see CreateServerImageJob} fires the
 *                        provider create-image action and starts polling
 *   creating → completed when the action finishes and provider_image_id is known
 *   * → failed           when the provider errored / timed out (error_message set)
 *
 * Distinct from {@see RedisSnapshot} (cache RDB) and {@see Snapshot} (site DB
 * dump) — this is the whole-machine image, the "images" surface of the unified
 * Snapshots workspace. Only providers whose service wraps the image API qualify
 * (see {@see ServerProvider::supportsImageSnapshots()}).
 */
class ServerImage extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CREATING = 'creating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'server_images';

    protected $fillable = [
        'server_id',
        'organization_id',
        'user_id',
        'provider',
        'provider_image_id',
        'provider_action_id',
        'name',
        'status',
        'region',
        'bytes',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
