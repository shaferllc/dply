<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persistent record of a single server-scoped shell run dispatched from
 * the Run page ({@see \App\Livewire\Servers\WorkspaceRun}).
 *
 * Ad-hoc one-offs and saved-command (ServerRecipe) runs both land here.
 * The row is inserted in 'queued' state by
 * {@see \App\Services\Servers\ServerCommandRunner} and driven to a settled
 * state by {@see \App\Jobs\RunServerCommandJob}, which also flushes output
 * incrementally so the page can stream it via wire:poll. The row is the
 * durable audit trail of who ran what on the box.
 *
 * @property string $id
 * @property string $server_id
 * @property ?string $server_recipe_id
 * @property string $source 'adhoc' | 'recipe'
 * @property string $command
 * @property string $display_command
 * @property ?string $container_scope_id
 * @property ?string $container_scope_name
 * @property string $status 'queued' | 'running' | 'completed' | 'failed'
 * @property ?int $exit_code
 * @property ?string $stdout
 * @property ?string $stderr
 * @property ?string $queued_by_user_id
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property-read ?Server $server
 * @property-read ?ServerRecipe $recipe
 * @property-read ?User $queuedByUser
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerCommandRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'server_recipe_id',
        'source',
        'command',
        'display_command',
        'container_scope_id',
        'container_scope_name',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'queued_by_user_id',
        'started_at',
        'finished_at',
    ];

    public const SOURCE_ADHOC = 'adhoc';

    public const SOURCE_RECIPE = 'recipe';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<ServerRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ServerRecipe::class, 'server_recipe_id');
    }

    /** @return BelongsTo<User, $this> */
    public function queuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by_user_id');
    }
}
