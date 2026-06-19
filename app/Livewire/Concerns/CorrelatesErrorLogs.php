<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ErrorEvent;
use App\Modules\Logs\Services\ServerLogCorrelator;

/**
 * Tier-1 dply Logs correlation for an error stream: jump from a failure straight
 * into the host log slice surrounding it. Shared by the server- and site-scoped
 * Errors views — each supplies how to resolve an error in its own scope via
 * {@see findCorrelatableError()}, and an SSH-free ClickHouse read pulls the window
 * (safe to run inline, like the Logs explorer).
 *
 * Requires the host to expose $server and an authorizeErrorAccess() guard
 * ({@see SurfacesErrorStream}).
 */
trait CorrelatesErrorLogs
{
    /** True when the owning server ships logs — gates the per-error "Logs" jump. */
    public bool $showLogCorrelation = false;

    /** Drawer state for the "logs around this error" slice. */
    public bool $errorLogsOpen = false;

    public ?string $errorLogsLabel = null;

    /** @var array{instant:string,from:string,to:string,logs:list<array<string,mixed>>}|null */
    public ?array $errorLogsResult = null;

    /** Resolve the error within the host's scope (server- or site-scoped). */
    abstract protected function findCorrelatableError(string $errorId): ?ErrorEvent;

    /**
     * Open the "logs around this error" drawer: the host log slice surrounding
     * when the error occurred, on its server. A ClickHouse READ (like the Logs
     * explorer), not SSH — safe to run inline. Errors not on a server, or with no
     * shipped logs in the window, simply show an empty drawer.
     */
    public function openLogsForError(string $errorId): void
    {
        $this->authorizeErrorAccess();

        $error = $this->findCorrelatableError($errorId);
        if ($error === null) {
            return;
        }

        $instant = $error->occurred_at ?? $error->created_at;
        $this->errorLogsLabel = $instant?->toDayDateTimeString();
        $this->errorLogsResult = app(ServerLogCorrelator::class)->forErrorEvent($error);
        $this->errorLogsOpen = true;
    }

    public function closeLogsForError(): void
    {
        $this->errorLogsOpen = false;
        $this->errorLogsResult = null;
        $this->errorLogsLabel = null;
    }
}
