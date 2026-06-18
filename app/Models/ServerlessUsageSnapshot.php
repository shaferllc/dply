<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A daily usage roll-up for a dply-managed serverless function. Mirrors
 *                      {@see EdgeUsageSnapshot}: one row per (site, day, source), summed over the
 *                      billing month to compute the metered usage charge on top of the flat fee.
 * @property int $gib_seconds
 * @property int $invocations
 * @property array<string, mixed> $meta
 * @property ?string $organization_id
 * @property Carbon $period_end
 * @property Carbon $period_start
 * @property ?string $site_id
 * @property string $source
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerlessUsageSnapshot extends Model
{
    use HasUlids;

    public const SOURCE_PLACEHOLDER = 'placeholder';

    /** Rolled up from the operational function_invocations log. */
    public const SOURCE_FUNCTION_INVOCATIONS = 'function_invocations';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'site_id',
        'period_start',
        'period_end',
        'invocations',
        'gib_seconds',
        'source',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'invocations' => 'integer',
            'gib_seconds' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
