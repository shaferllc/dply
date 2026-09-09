<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Models\CloudDatabase;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Support\Sites\ManagedDatabaseProvisionConsole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resizes a managed DigitalOcean database / Valkey cluster in place and
 * stamps the new plan on the CloudDatabase (and the site binding, when the
 * cluster was provisioned for one) once it is online.
 *
 * $siteBindingId is nullable because the same cluster reaches this job from
 * two places: a VM site's `database` binding, which owns a console run and a
 * status to update, and the standalone managed-databases surface, where the
 * CloudDatabase row is the only thing there is. The provider call is identical;
 * only the reporting differs.
 */
class ResizeManagedDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    private const MAX_ATTEMPTS = 40;

    public function __construct(
        public string $cloudDatabaseId,
        public ?string $siteBindingId,
        public string $size,
        public int $attempt = 1,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(DatabaseRouter $router): void
    {
        $database = CloudDatabase::query()->find($this->cloudDatabaseId);
        if ($database === null) {
            return;
        }

        $binding = $this->siteBindingId !== null
            ? SiteBinding::query()->find($this->siteBindingId)
            : null;

        // A binding id that no longer resolves means the database was detached
        // mid-resize — stop rather than report against a stale row.
        if ($this->siteBindingId !== null && ! $binding instanceof SiteBinding) {
            return;
        }

        $size = CloudDatabase::resolveSizeSlug($this->size);
        $from = $database->backendSizeSlug();
        $run = $binding instanceof SiteBinding ? $this->consoleRun($binding, $size) : null;
        $backend = $router->backendFor($database);

        try {
            if ($this->attempt === 1) {
                $backend->resize($database, $size);
                if ($run instanceof ConsoleAction) {
                    ManagedDatabaseProvisionConsole::resizeAccepted($run, $database, $from, $size);
                }
            }

            $result = $backend->poll($database);
        } catch (Throwable $e) {
            Log::error('database.managed.resize_failed', [
                'cloud_database_id' => $database->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($database, $binding, $e->getMessage(), $run);

            return;
        }

        $status = (string) $result['status'];
        $online = $status === 'online';

        if (! $online) {
            if ($run instanceof ConsoleAction) {
                ManagedDatabaseProvisionConsole::resizePoll(
                    $run,
                    $database,
                    $status,
                    $size,
                    $this->attempt,
                    self::MAX_ATTEMPTS,
                );
            }

            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->markFailed($database, $binding, 'The cluster resize did not finish in time.', $run);

                return;
            }

            self::dispatch(
                $this->cloudDatabaseId,
                $this->siteBindingId,
                $size,
                $this->attempt + 1,
                $run instanceof ConsoleAction ? (string) $run->id : $this->seededConsoleRunId,
            )->delay(now()->addSeconds(20));

            return;
        }

        $meta = $database->meta;
        unset($meta['resizing_to'], $meta['error'], $meta['error_at']);
        $database->forceFill(['size' => $size, 'meta' => $meta])->save();

        if ($binding instanceof SiteBinding) {
            $config = is_array($binding->config) ? $binding->config : [];
            $config['size'] = $size;
            unset($config['resizing_to']);
            $binding->forceFill([
                'config' => $config,
                'last_error' => null,
            ])->save();
        }

        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::noteIfNew(
                $run,
                'digitalocean',
                __('Cluster is on :size.', ['size' => $size]),
            );
            ManagedDatabaseProvisionConsole::complete($run);
        }
    }

    private function consoleRun(SiteBinding $binding, string $size): ?ConsoleAction
    {
        $site = $binding->site_id !== null
            ? Site::query()->find($binding->site_id)
            : null;

        if (! $site instanceof Site) {
            return null;
        }

        return ManagedDatabaseProvisionConsole::ensureResize($site, $binding, $size, $this->seededConsoleRunId);
    }

    private function markFailed(CloudDatabase $database, ?SiteBinding $binding, string $error, ?ConsoleAction $run = null): void
    {
        // The database row carries the failure too — on the standalone surface
        // there is no binding to read it from.
        $meta = $database->meta;
        unset($meta['resizing_to']);
        $meta['error'] = $error;
        $meta['error_at'] = now()->toIso8601String();
        $database->forceFill(['meta' => $meta])->save();

        if ($binding instanceof SiteBinding) {
            $config = is_array($binding->config) ? $binding->config : [];
            unset($config['resizing_to']);

            $binding->forceFill([
                'config' => $config,
                'last_error' => $error,
            ])->save();
        }

        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::fail($run, $error);
        }
    }
}
