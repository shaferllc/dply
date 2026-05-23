<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSubscriptionSyncEvent extends Model
{
    use HasUlids;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_OP = 'no_op';

    protected $fillable = [
        'organization_id',
        'trigger',
        'status',
        'changes',
        'desired_state',
        'monthly_total_cents',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'desired_state' => 'array',
            'monthly_total_cents' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
