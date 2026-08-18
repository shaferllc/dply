<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\CloudDatabase;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Cloud\Jobs\ProvisionCloudDatabaseJob;
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
 * Provisions a managed-database cluster for a site's `database` binding and,
 * once the cluster is online, wires its connection into the binding.
 *
 * This is the VM-site counterpart to the Cloud module's
 * {@see ProvisionCloudDatabaseJob} (which fans out to
 * container sites via the cloud_database_site pivot). Here the attachment is a
 * {@see SiteBinding}: when the cluster comes online we lock its network down to
 * the app server, then drop the connection vars into the binding's injected_env
 * and flip it to `configured`. The binding is merged into the deploy
 * environment at deploy time, so we deliberately do NOT push .env or redeploy —
 * the UI surfaces a "Redeploy to use it" prompt instead (no surprise restart of
 * a running site).
 *
 * Clusters take minutes, so the job is its own poll loop: create, store the
 * backend id, then re-dispatch with a delay until the provider reports online.
 */
class ProvisionManagedDatabaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Re-dispatch cap — ~13 min at 20s spacing, enough for a small cluster. */
    private const MAX_ATTEMPTS = 40;

    public function __construct(
        public string $cloudDatabaseId,
        public string $siteBindingId,
        public string $serverId,
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

        // The binding was detached (or replaced) while the cluster spun up —
        // stop polling; the orphaned cluster is reaped by teardown elsewhere.
        if ($binding->status === SiteBinding::STATUS_ERROR && $database->status === CloudDatabase::STATUS_DELETING) {
            return;
        }

        $run = $this->consoleRun($database, $binding);
        $backend = $router->backendFor($database);

        try {
            $result = $backend->poll($database);
            $database->refresh();
            if ($run instanceof ConsoleAction && $this->attempt === 1) {
                ManagedDatabaseProvisionConsole::createAccepted($run, $database);
            }
        } catch (Throwable $e) {
            Log::error('database.managed.provision_failed', [
                'cloud_database_id' => $database->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($database, $binding, $e->getMessage(), $run);

            return;
        }

        $connection = $result['connection'];
        $online = $result['status'] === 'online' && (string) ($connection['host'] ?? '') !== '';

        if (! $online) {
            if ($run instanceof ConsoleAction) {
                ManagedDatabaseProvisionConsole::poll(
                    $run,
                    $database,
                    (string) ($result['status'] ?? ''),
                    $this->attempt,
                    self::MAX_ATTEMPTS,
                );
            }

            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->markFailed($database, $binding, 'The database cluster did not come online in time.', $run);

                return;
            }

            self::dispatch(
                $this->cloudDatabaseId,
                $this->siteBindingId,
                $this->serverId,
                $this->attempt + 1,
                $run instanceof ConsoleAction ? (string) $run->id : $this->seededConsoleRunId,
            )->delay(now()->addSeconds(20));

            return;
        }

        // Online — persist the connection block (encrypted) on the row.
        $meta = $database->meta;
        unset($meta['error'], $meta['error_at']);
        $meta['provisioned_at'] = now()->toIso8601String();

        $database->forceFill([
            'status' => CloudDatabase::STATUS_ACTIVE,
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (string) ($connection['port'] ?? ''),
                'username' => (string) ($connection['user'] ?? $connection['username'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
            'meta' => $meta,
        ])->save();

        // Close the cluster to the public internet — only the app server may
        // reach it. Best-effort (the backend logs + no-ops on failure).
        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::online($run);
        }

        $server = Server::query()->find($this->serverId);
        if ($server instanceof Server) {
            $backend->lockNetworkTo($database, $server);
            if ($run instanceof ConsoleAction) {
                ManagedDatabaseProvisionConsole::noteIfNew(
                    $run,
                    'digitalocean',
                    __('Restricted the cluster to this app server.'),
                );
            }
        }

        $this->wireBinding($database, $binding);

        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::noteIfNew(
                $run,
                'dply',
                __('Connection variables are ready for the next deploy.'),
            );
            ManagedDatabaseProvisionConsole::complete($run);
        }
    }

    private function consoleRun(CloudDatabase $database, SiteBinding $binding): ?ConsoleAction
    {
        $site = $binding->site_id !== null
            ? Site::query()->find($binding->site_id)
            : null;

        if (! $site instanceof Site) {
            return null;
        }

        return ManagedDatabaseProvisionConsole::ensure($site, $binding, $database, $this->seededConsoleRunId);
    }

    /**
     * Drop the cluster's connection vars into the binding and flip it to
     * configured. The binding is the source of truth merged at deploy time, so
     * this readies the connection without touching the live .env or redeploying.
     */
    private function wireBinding(CloudDatabase $database, SiteBinding $binding): void
    {
        $prefix = (string) (($binding->config['env_prefix'] ?? '') ?: $database->defaultEnvPrefix());

        // Stamp when the connection became available so the resource map can
        // tell whether the site has been deployed SINCE (binding env applies at
        // deploy time) — drives the "redeploy to apply" prompt without nagging
        // once a deploy has actually picked the vars up.
        $config = $binding->config;
        $config['connection_ready_at'] = now()->toIso8601String();

        $binding->forceFill([
            'status' => SiteBinding::STATUS_CONFIGURED,
            'injected_env' => $database->connectionEnvVars($prefix),
            'config' => $config,
            'last_error' => null,
        ])->save();

        // For PHP sites, make sure the client extension for the engine is
        // present so the app can dial the managed cluster after a redeploy.
        if ($database->engine !== CloudDatabase::ENGINE_REDIS && $binding->site_id !== null) {
            $site = Site::query()->find($binding->site_id);
            if ($site instanceof Site && strtolower((string) $site->runtime) === 'php') {
                EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, $database->engine);
            }
        }
    }

    private function markFailed(CloudDatabase $database, SiteBinding $binding, string $error, ?ConsoleAction $run = null): void
    {
        $meta = $database->meta;
        $meta['error'] = $error;
        $meta['error_at'] = now()->toIso8601String();

        $database->forceFill([
            'status' => CloudDatabase::STATUS_FAILED,
            'meta' => $meta,
        ])->save();

        $binding->forceFill([
            'status' => SiteBinding::STATUS_ERROR,
            'last_error' => $error,
        ])->save();

        if ($run instanceof ConsoleAction) {
            ManagedDatabaseProvisionConsole::fail($run, $error);
        }
    }
}
