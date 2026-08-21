<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One namespace's jobs-pushed count for one day.
 *
 * Observational. Nothing bills from this — see the migration and
 * docs/adr/managed-services-tier.md, decision 6.
 *
 * @property int $id
 * @property string $namespace_id
 * @property Carbon $usage_date
 * @property int $jobs_pushed
 */
class QueueNamespaceUsageDaily extends Model
{
    protected $table = 'dply_queue_namespace_usage_daily';

    protected $fillable = [
        'namespace_id',
        'usage_date',
        'jobs_pushed',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'jobs_pushed' => 'integer',
        ];
    }
}
