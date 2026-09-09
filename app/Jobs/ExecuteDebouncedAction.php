<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Decorators\DebounceDecorator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Runs an action after {@see DebounceDecorator}'s quiet period.
 *
 * Later calls for the same cache key refresh arguments and `scheduled_at`.
 * Earlier delayed jobs no-op if the window was pushed forward or another job
 * already consumed the pending payload.
 */
class ExecuteDebouncedAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  class-string  $actionClass
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        public string $actionClass,
        public string $cacheKey,
        public array $arguments,
    ) {}

    public function handle(): void
    {
        $pending = Cache::get($this->cacheKey);

        if (! is_array($pending)) {
            return;
        }

        $scheduledAt = $pending['scheduled_at'] ?? null;

        if ($scheduledAt !== null && now()->lessThan($scheduledAt)) {
            return;
        }

        /** @var array<int, mixed> $arguments */
        $arguments = is_array($pending['arguments'] ?? null)
            ? $pending['arguments']
            : $this->arguments;

        Cache::forget($this->cacheKey);

        if (! class_exists($this->actionClass)) {
            return;
        }

        $action = app($this->actionClass);

        if (! is_object($action) || ! method_exists($action, 'handle')) {
            return;
        }

        $action->handle(...$arguments);
    }
}
