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
 * @property string $rule_key
 * @property string $severity
 * @property string $signature
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property string $title
 * @property ?string $summary
 * @property ?array<string, mixed> $payload
 * @property ?Carbon $first_observed_at
 * @property ?Carbon $last_observed_at
 * @property ?Carbon $resolved_at
 * @property ?string $dismissed_by_user_id
 * @property ?Carbon $dismissed_at
 */
class DeployIntelligenceAlert extends Model
{
    use HasUlids;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_DANGER = 'danger';

    public const RULE_SLOW_BUILD = 'slow_build';

    public const RULE_TLS_EXPIRING = 'tls_expiring';

    public const RULE_ENV_DRIFT = 'env_drift';

    protected $fillable = [
        'organization_id',
        'rule_key',
        'severity',
        'signature',
        'subject_type',
        'subject_id',
        'title',
        'summary',
        'payload',
        'first_observed_at',
        'last_observed_at',
        'resolved_at',
        'dismissed_by_user_id',
        'dismissed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function dismisser(): BelongsTo {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null && $this->dismissed_at === null;
    }
}
