<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-org daily rollup of dply Logs ingest volume, metered from the ClickHouse
 * log store ({@see \App\Services\Logs\ServerLogUsageMeter}). The billable unit is
 * ingest volume (bytes accepted at the aggregator), measured here as
 * `sum(length(message))` — what we actually stored after edge redaction/drops,
 * which is what's defensible to the customer. See docs/SERVER_LOGS_BILLING.md.
 *
 * @property string $id
 * @property string $organization_id
 * @property Carbon $day
 * @property int $events
 * @property int $bytes
 * @property string $source
 * @property ?array<string, mixed> $meta
 * @property-read ?Organization $organization
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerLogUsageDaily extends Model
{
    use HasUlids;

    /** Rolled up from the ClickHouse store (the billable source of truth). */
    public const SOURCE_CLICKHOUSE = 'clickhouse';

    /** Hand-entered / adjustment rows (credits, backfills, disputes). */
    public const SOURCE_MANUAL = 'manual';

    protected $table = 'server_log_usage_daily';

    protected $fillable = [
        'organization_id',
        'day',
        'events',
        'bytes',
        'source',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'events' => 'integer',
            'bytes' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
