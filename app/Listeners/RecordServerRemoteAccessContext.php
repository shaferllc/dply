<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Servers\ServerRemoteAccessContext;
use App\Services\Servers\ServerRemoteAccessLogger;
use App\Services\SshConnection;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

/**
 * Binds {@see ServerRemoteAccessContext} while queue jobs run so
 * {@see SshConnection} can log platform SSH access.
 */
final class RecordServerRemoteAccessContext
{
    public function __construct(
        private ServerRemoteAccessLogger $logger,
    ) {}

    public function handleProcessing(JobProcessing $event): void
    {
        if (! (bool) config('server_ssh_access.log_remote_access', true)) {
            return;
        }

        if (app()->bound(ServerRemoteAccessContext::class)) {
            return;
        }

        $class = $event->job->resolveName();
        if (! is_string($class) || $class === '') {
            return;
        }

        if (in_array($class, (array) config('server_ssh_access.skip_job_classes', []), true)) {
            return;
        }

        $instance = $this->resolveJobInstance($event);
        $uuid = method_exists($event->job, 'uuid') ? $event->job->uuid() : null;

        app()->instance(
            ServerRemoteAccessContext::class,
            ServerRemoteAccessContext::forJob($class, $instance, is_string($uuid) ? $uuid : null),
        );
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->finish();
    }

    public function handleFailed(JobFailed $event): void
    {
        if (app()->bound(ServerRemoteAccessContext::class)) {
            app(ServerRemoteAccessContext::class)->failed = true;
        }

        $this->finish();
    }

    private function finish(): void
    {
        try {
            $this->logger->finishContext();
        } catch (\Throwable $e) {
            Log::warning('server.remote_access.finish_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveJobInstance(JobProcessing $event): ?object
    {
        try {
            $payload = $event->job->payload();
            $command = unserialize($payload['data']['command'], ['allowed_classes' => true]);

            // A stale release can serialize a command whose class no longer
            // matches; PHP rebuilds it as __PHP_Incomplete_Class, which throws
            // on any property access. Treat those as "no instance".
            if (! is_object($command) || $command instanceof \__PHP_Incomplete_Class) {
                return null;
            }

            return $command;
        } catch (\Throwable) {
            return null;
        }
    }
}
