<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A queue job that failed inside a serverless function.
 *
 * Reported outward by the injected handler during a queue slot (a function
 * has no worker process to run `queue:failed` against) and written here by
 * the pump. A mirror for the UI — the app's own `failed_jobs` row remains
 * the thing `queue:retry` acts on.
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $uuid
 * @property ?string $connection_name
 * @property ?string $queue
 * @property ?string $job_class
 * @property ?string $exception_message
 * @property ?string $exception_excerpt
 * @property ?Carbon $retried_at
 * @property ?Carbon $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?Site $site
 */
class ServerlessFailedJob extends Model
{
    use HasUlids;

    /** Keep a stack trace readable without letting it bloat the row. */
    public const EXCEPTION_EXCERPT_CHARS = 4000;

    protected $fillable = [
        'site_id',
        'uuid',
        'connection_name',
        'queue',
        'job_class',
        'exception_message',
        'exception_excerpt',
        'retried_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'retried_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Whether an operator has sent this job back to the queue. */
    public function wasRetried(): bool
    {
        return $this->retried_at !== null;
    }

    /**
     * The class name without its namespace — what an operator scans a list
     * for. Falls back to the full string when there is no namespace.
     */
    public function shortJobClass(): string
    {
        $class = trim((string) $this->job_class);

        if ($class === '') {
            return '—';
        }

        return class_basename($class);
    }

    /** First line of the exception, for a one-line list row. */
    public function headline(): string
    {
        $message = trim((string) $this->exception_message);

        if ($message === '') {
            return __('No exception message recorded.');
        }

        return Str::limit(trim((string) strtok($message, "\n")), 160);
    }
}
