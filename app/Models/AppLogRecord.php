<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Logs\Console\LogDrainListen;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One application log record received from a site via the dply Realtime drain
 *                      (Phase 5). Written by the drain receiver ({@see LogDrainListen})
 *                      and read by the App logs panel. Append-only — a row is written once.
 * @property string $site_id
 * @property string|null $level
 * @property string $message
 * @property string $channel
 * @property array<string, mixed> $context
 * @property ?Carbon $created_at
 * @property ?Carbon $logged_at
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AppLogRecord extends Model
{
    use HasUlids;

    protected $table = 'app_logs';

    /** Rows are written once; only created_at is tracked. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'channel',
        'level',
        'message',
        'context',
        'logged_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
