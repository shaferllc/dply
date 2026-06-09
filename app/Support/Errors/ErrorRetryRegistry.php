<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Jobs\InstallDatabaseEngineJob;
use App\Jobs\UninstallDatabaseEngineJob;
use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\ServerDatabaseEngine;
use Closure;

/**
 * Maps an error {@see ErrorEvent::$category} to a handler that reconstructs and
 * re-dispatches the original operation. A row in the Errors view shows "Retry"
 * only when its category is registered here — there is no blind re-dispatch, so
 * every retryable path is an explicit, reviewed re-run of an existing job.
 *
 * To make a category retryable, add a handler that resolves the origin from the
 * error's source row and dispatches the same job the original flow used. The
 * re-dispatched job seeds its own console run (and, if it fails again, produces
 * a fresh ErrorEvent via the listeners) — so retries are self-tracking.
 */
class ErrorRetryRegistry
{
    /** @var array<string, Closure(ErrorEvent, ?string): bool>|null */
    private ?array $handlers = null;

    public function isRetryable(string $category): bool
    {
        return array_key_exists($category, $this->handlers());
    }

    /** Run the handler for this error. Returns false when not retryable or the origin is gone. */
    public function retry(ErrorEvent $event, ?string $userId = null): bool
    {
        $handler = $this->handlers()[$event->category] ?? null;
        if ($handler === null) {
            return false;
        }

        return $handler($event, $userId);
    }

    /**
     * @return array<string, Closure(ErrorEvent, ?string): bool>
     */
    private function handlers(): array
    {
        return $this->handlers ??= [
            'db_engine_install' => fn (ErrorEvent $e, ?string $userId): bool => $this->reinstallEngine($e, $userId, true),
            'db_engine_uninstall' => fn (ErrorEvent $e, ?string $userId): bool => $this->reinstallEngine($e, $userId, false),
        ];
    }

    /** Re-dispatch the install/uninstall job for the engine behind a failed db_engine_* run. */
    private function reinstallEngine(ErrorEvent $event, ?string $userId, bool $install): bool
    {
        $engine = $this->engineFromSource($event);
        if (! $engine instanceof ServerDatabaseEngine) {
            return false;
        }

        if ($install) {
            InstallDatabaseEngineJob::dispatch((string) $engine->id, $userId);
        } else {
            UninstallDatabaseEngineJob::dispatch((string) $engine->id, $userId);
        }

        return true;
    }

    /** The ServerDatabaseEngine the error's source ConsoleAction was about. */
    private function engineFromSource(ErrorEvent $event): ?ServerDatabaseEngine
    {
        if ($event->source_type !== (new ConsoleAction)->getMorphClass()) {
            return null;
        }

        $action = ConsoleAction::query()->find($event->source_id);
        $subject = $action?->subject;

        return $subject instanceof ServerDatabaseEngine ? $subject : null;
    }
}
