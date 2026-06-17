<?php

namespace App\Models;

use App\Jobs\Concerns\WritesConsoleAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $kind
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 * @property ?\Illuminate\Support\Carbon $dismissed_at
 * @property ?string $error
 * @property ?string $label
 * @property ?array $output
 * @property ?string $user_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Model $subject
 * @property-read ?User $user
 *
 * One row per backgrounded action whose progress we want to surface in the
 * page-top console banner. Polymorphic so anything (Site, Server, Deploy, …)
 * can have console-able runs without per-model schema gymnastics.
 *
 * Lifecycle (driven by {@see WritesConsoleAction}):
 *   queued  → seedQueuedRun()   — row exists before the worker picks it up
 *   running → beginConsoleRun() — worker started, started_at stamped
 *   completed | failed          — terminal; finished_at + (optional) error set
 *
 * Output is a versioned JSON wrapper:
 *   { v: 1, lines: [{ t: epochMs, level: "info|step|warn|error|success", source: "nginx", line: "..." }, ...] }
 *
 * Append trims to config('console_actions.max_lines') so a chatty run can't
 * grow the row unboundedly.
 */
class ConsoleAction extends Model
{
    /** @use HasFactory<ConsoleActionFactory> */
    use HasFactory, HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const LEVEL_INFO = 'info';

    public const LEVEL_STEP = 'step';

    public const LEVEL_WARN = 'warn';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_SUCCESS = 'success';

    /** @var list<string> */
    public const LEVELS = [
        self::LEVEL_INFO,
        self::LEVEL_STEP,
        self::LEVEL_WARN,
        self::LEVEL_ERROR,
        self::LEVEL_SUCCESS,
    ];

    protected $table = 'console_actions';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'kind',
        'status',
        'started_at',
        'finished_at',
        'dismissed_at',
        'error',
        'label',
        'output',
        'user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'output' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    /**
     * Latest non-dismissed row for a subject. Pages call this to drive the banner.
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    public function scopeNotDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function isQueuedStalled(): bool
    {
        if ($this->status !== self::STATUS_QUEUED || $this->created_at === null) {
            return false;
        }

        $threshold = (int) config('console_actions.queued_stalled_after_seconds', 45);

        return $this->created_at->lt(now()->subSeconds($threshold));
    }

    public function isStale(): bool
    {
        if ($this->status === self::STATUS_RUNNING && $this->started_at !== null) {
            $threshold = (int) config('console_actions.stale_after_seconds', 600);

            return $this->started_at->lt(now()->subSeconds($threshold));
        }

        if ($this->status === self::STATUS_QUEUED) {
            return $this->isQueuedStalled();
        }

        return false;
    }

    public static function queueWorkerStalledMessage(): string
    {
        $connection = (string) config('queue.default', 'sync');
        $seconds = (int) config('console_actions.queued_stalled_after_seconds', 45);

        if ($connection === 'sync') {
            return __('No worker progress after :seconds seconds. Check that queue jobs are running.', [
                'seconds' => $seconds,
            ]);
        }

        return __('No queue worker picked up this task after :seconds seconds.', [
            'seconds' => $seconds,
        ]);
    }

    /**
     * Materialised view of the JSON column as a list of entries. Defensive against
     * a row written by an older writer (no wrapper) or a manual SQL insert.
     *
     * @return list<array{t: int, level: string, source: ?string, line: string}>
     */
    public function lines(): array
    {
        $output = $this->output;
        if (! is_array($output)) {
            return [];
        }

        // v1: { v: 1, lines: [...] }; tolerate "raw list" shape too in case
        // something writes [{...}, ...] directly.
        $lines = $output['lines'] ?? (array_is_list($output) ? $output : []);
        if (! is_array($lines)) {
            return [];
        }

        $normalized = [];
        foreach ($lines as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $line = (string) ($entry['line'] ?? '');
            if ($line === '') {
                continue;
            }
            $normalized[] = [
                't' => (int) ($entry['t'] ?? 0),
                'level' => is_string($entry['level'] ?? null) ? (string) $entry['level'] : self::LEVEL_INFO,
                'source' => isset($entry['source']) && $entry['source'] !== '' ? (string) $entry['source'] : null,
                'line' => $line,
            ];
        }

        return $normalized;
    }
}
