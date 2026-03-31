<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    use HasUlids;

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referrer_organization_id',
        'bonus_credit_cents',
        'stripe_balance_transaction_id',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function referrerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referrer_organization_id');
    }
}
