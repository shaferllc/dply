<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 *                      One OpenWhisk action on a serverless function-Site.
 *                      A Site is an OpenWhisk package holding N actions. A `kind=code` action is
 *                      a deployable function (it has a runtime, an entrypoint, resource limits);
 *                      a `kind=sequence` action is a codeless composition that chains other
 *                      actions and carries an ordered `components` list instead.
 *                      Per-action concerns — runtime, limits, the invocation URL, the scheduled
 *                      trigger, logs — hang off this row rather than `Site.meta.serverless`.
 * @property array<string, mixed> $components
 * @property int $concurrency
 * @property string $entrypoint
 * @property string $kind
 * @property int $memory_mb
 * @property array<string, mixed> $meta
 * @property string $name
 * @property string $runtime
 * @property ?string $site_id
 * @property int $timeout_ms
 * @property array<string, mixed> $trigger
 * @property string $url
 * @property-read ?Site $site
 * @property-read Collection<int, FunctionInvocation> $invocations
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FunctionAction extends Model
{
    use HasUlids;

    /** A deployable function with its own code. */
    public const KIND_CODE = 'code';

    /** A codeless composition chaining other actions (OpenWhisk sequence). */
    public const KIND_SEQUENCE = 'sequence';

    protected $fillable = [
        'site_id',
        'name',
        'kind',
        'runtime',
        'entrypoint',
        'memory_mb',
        'timeout_ms',
        'concurrency',
        'url',
        'trigger',
        'components',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'memory_mb' => 'integer',
            'timeout_ms' => 'integer',
            'concurrency' => 'integer',
            'trigger' => 'array',
            'components' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<FunctionInvocation, $this> */
    public function invocations(): HasMany
    {
        return $this->hasMany(FunctionInvocation::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCode(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_CODE);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSequences(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_SEQUENCE);
    }

    public function isSequence(): bool
    {
        return $this->kind === self::KIND_SEQUENCE;
    }
}
