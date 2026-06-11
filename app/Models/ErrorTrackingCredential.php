<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable error-tracking credential set scoped to an organization, so the
 * team can attach the same Sentry/Bugsnag/Flare project to multiple sites
 * without re-entering the DSN/key each time.
 *
 * The provider-specific secret (a DSN for Sentry, an API key for Bugsnag/Flare)
 * lives in the encrypted {@see $credentials} JSON column so one model handles
 * every provider shape. Mirrors {@see LogDrainCredential}.
 */
class ErrorTrackingCredential extends Model
{
    use HasUlids;

    protected $table = 'error_tracking_credentials';

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
