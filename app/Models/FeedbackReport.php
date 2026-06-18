<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $admin_notes
 * @property ?string $assigned_to_user_id
 * @property array<string, mixed> $attachments
 * @property ?Carbon $attachments_pruned_at
 * @property array<string, mixed> $context
 * @property ?string $description
 * @property string $ip_address
 * @property ?string $organization_id
 * @property string $reference
 * @property ?Carbon $resolved_at
 * @property ?string $screenshot_path
 * @property ?string $severity
 * @property string $status
 * @property string $title
 * @property string $type
 * @property ?string $user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FeedbackReport extends Model
{
    use HasUlids;

    public const TYPE_BUG = 'bug';

    public const TYPE_IDEA = 'idea';

    public const TYPE_QUESTION = 'question';

    public const STATUS_NEW = 'new';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_WONT_FIX = 'wont_fix';

    public const STATUS_DUPLICATE = 'duplicate';

    /** Statuses that mean "done" — eligible for attachment pruning. */
    public const TERMINAL_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_WONT_FIX,
        self::STATUS_DUPLICATE,
    ];

    protected $fillable = [
        'reference',
        'user_id',
        'organization_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'context',
        'screenshot_path',
        'attachments',
        'admin_notes',
        'assigned_to_user_id',
        'ip_address',
        'resolved_at',
        'attachments_pruned_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'attachments' => 'array',
            'resolved_at' => 'datetime',
            'attachments_pruned_at' => 'datetime',
        ];
    }

    public static function newReference(): string
    {
        return 'FB-'.Str::upper(Str::random(8));
    }

    /**
     * @return list<string>
     */
    public static function typeKeys(): array
    {
        return array_keys(config('feedback.types', []));
    }

    /**
     * @return list<string>
     */
    public static function statusKeys(): array
    {
        return array_keys(config('feedback.statuses', []));
    }

    /**
     * @return list<string>
     */
    public static function severityKeys(): array
    {
        return array_keys(config('feedback.severities', []));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '' || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeType(Builder $query, ?string $type): Builder
    {
        if ($type === null || $type === '' || $type === 'all') {
            return $query;
        }

        return $query->where('type', $type);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSeverity(Builder $query, ?string $severity): Builder
    {
        if ($severity === null || $severity === '' || $severity === 'all') {
            return $query;
        }

        return $query->where('severity', $severity);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $term = '%'.Str::lower(trim($search)).'%';

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                ->orWhereRaw('LOWER(reference) LIKE ?', [$term]);
        });
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isHighPriority(): bool
    {
        return in_array($this->severity, ['high', 'critical'], true);
    }

    public function typeLabel(): string
    {
        return (string) (config('feedback.types.'.$this->type) ?? $this->type);
    }

    public function statusLabel(): string
    {
        return (string) (config('feedback.statuses.'.$this->status) ?? $this->status);
    }

    public function severityLabel(): ?string
    {
        if ($this->severity === null || $this->severity === '') {
            return null;
        }

        return (string) (config('feedback.severities.'.$this->severity) ?? $this->severity);
    }
}
