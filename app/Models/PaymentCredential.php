<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable payments-provider credential set scoped to an organization
 * (Stripe / Paddle, via Laravel Cashier). Provider-specific keys live in the
 * encrypted {@see $credentials} JSON column. Mirrors {@see ErrorTrackingCredential}.
 */
class PaymentCredential extends Model
{
    use HasUlids;

    protected $table = 'payment_credentials';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'provider',
        'name',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
