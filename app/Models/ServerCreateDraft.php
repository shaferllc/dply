<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * In-progress draft for the multi-step server create wizard.
 *
 * One row per (user, organization). The payload column holds the entire ServerCreateForm
 * field set encrypted at rest (same APP_KEY mechanism used for ssh_private_key on Server).
 * Drafts auto-expire 14 days after the last save; a daily scheduled command prunes them.
 *
 * @property string $id
 * @property string $user_id
 * @property string $organization_id
 * @property int $step
 * @property array<string, mixed> $payload
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerCreateDraft extends Model
{
    use HasFactory, HasUlids;

    public const TTL_DAYS = 14;

    public const TOTAL_STEPS = 4;

    protected $fillable = [
        'user_id',
        'organization_id',
        'step',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Refresh the expiry window. Called on every save so an actively-iterating user does not
     * lose their draft mid-flow.
     */
    public function bumpExpiry(): void
    {
        $this->expires_at = now()->addDays(self::TTL_DAYS);
    }

    public static function forCurrentScope(?User $user, ?Organization $organization): ?self
    {
        if ($user === null || $organization === null) {
            return null;
        }

        return static::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->first();
    }
}
