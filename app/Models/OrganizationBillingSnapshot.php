<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class OrganizationBillingSnapshot extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'snapshot_date',
        'monthly_total_cents',
        'category_breakdown',
        'fleet_counts',
        'edge_usage_cents',
        'subscription_interval',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'monthly_total_cents' => 'integer',
            'category_breakdown' => 'array',
            'fleet_counts' => 'array',
            'edge_usage_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }
}
