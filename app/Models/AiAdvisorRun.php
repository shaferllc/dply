<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $feature
 * @property string $status
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?string $triggered_by_user_id
 * @property ?array<string, mixed> $request_context
 * @property ?array<string, mixed> $response
 * @property ?int $prompt_tokens
 * @property ?int $completion_tokens
 * @property ?int $latency_ms
 * @property ?string $error_message
 * @property ?Carbon $finished_at
 * @property-read ?Organization $organization
 * @property-read ?User $triggeredBy
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AiAdvisorRun extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const FEATURE_OPS_COPILOT = 'ops_copilot';

    public const FEATURE_SHARED_HOST = 'shared_host';

    public const FEATURE_DOCS_ASK = 'docs_ask';

    protected $fillable = [
        'organization_id',
        'feature',
        'status',
        'subject_type',
        'subject_id',
        'triggered_by_user_id',
        'request_context',
        'response',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'error_message',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'request_context' => 'array',
            'response' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
