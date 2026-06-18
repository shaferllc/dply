<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      A reusable payments-provider credential set scoped to an organization
 *                      (Stripe / Paddle, via Laravel Cashier). Provider-specific keys live in the
 *                      encrypted {@see $credentials} JSON column. Mirrors {@see ErrorTrackingCredential}.
 * @property ?string $created_by_user_id
 * @property array<string, mixed> $credentials
 * @property string $name
 * @property ?string $organization_id
 * @property string $provider
 * @property-read ?Organization $organization
 * @property-read ?User $createdByUser
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
