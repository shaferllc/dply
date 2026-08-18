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
 * stamps the new plan on the CloudDatabase + binding once it is online.
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
        public string $siteBindingId,
        public string $size,
        public int $attempt = 1,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(DatabaseRouter $router): void
    {
        $database = CloudDatabase::query()->find($this->cloudDatabaseId);
        $binding = SiteBinding::query()->find($this->siteBindingId);
        if ($database === null || ! $binding instanceof SiteBinding) {
            return;
        }

        $size = CloudDatabase::resolveSizeSlug($this->size);
        $from = $database->backendSizeSlug();
        $run = $this->consoleRun($binding, $size);
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
            $this->markFailed($binding, $e->getMessage(), $run);

            return;
        }

        $status = (string) ($result['status'] ?? '');
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
                $this->markFailed($binding, 'The cluster resize did not finish in time.', $run);

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

        $database->forceFill(['size' => $size])->save();

        $config = is_array($binding->config) ? $binding->config : [];
        $config['size'] = $size;
        unset($config['resizing_to']);
        $binding->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();

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

    private function markFailed(SiteBinding $binding, string $error, ?ConsoleAction $run = null): void
    {
        $config = is_array($binding->config) ? $binding->config : [];
        unset($config['resizing_to']);

        $binding->forceFill([
            'config' => $config,
            'last_error' => $error,
        ])->save();

        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::fail($run, $error);
        }
    }
}
