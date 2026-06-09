<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One application log record received from a site via the dply Realtime drain
 * (Phase 5). Written by the drain receiver ({@see \App\Console\Commands\LogDrainListen})
 * and read by the App logs panel. Append-only — a row is written once.
 *
 * @property string $site_id
 * @property string|null $level
 * @property string $message
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

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
