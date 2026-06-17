<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      A reusable AI/LLM provider credential set scoped to an organization, so the
 *                      team can attach the same OpenAI/Anthropic/etc. key to multiple sites without
 *                      re-entering it. The provider-specific secret lives in the encrypted
 *                      {@see $credentials} JSON column. Mirrors {@see ErrorTrackingCredential}.
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
class AiCredential extends Model
{
    use HasUlids;

    protected $table = 'ai_credentials';

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
