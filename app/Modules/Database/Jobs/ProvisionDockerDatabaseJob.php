<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Servers\DockerDatabaseProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts a database engine in Docker on the site's server and wires the
 * binding once the container is accepting connections.
 */
class ProvisionDockerDatabaseJob implements ShouldQueue
{
    use Queueable;
    use WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public string $siteId,
        public string $serverDatabaseId,
        public string $siteBindingId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'docker_db_provision';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(
        DockerDatabaseProvisioner $provisioner,
        SiteBindingManager $manager,
    ): void {
        $server = Server::query()->find($this->serverId);
        $site = Site::query()->find($this->siteId);
        $database = ServerDatabase::query()->find($this->serverDatabaseId);
        $binding = SiteBinding::query()->find($this->siteBindingId);

        if (! $server instanceof Server || ! $site instanceof Site
            || ! $database instanceof ServerDatabase || ! $binding instanceof SiteBinding) {
            return;
        }

        if ($binding->status === SiteBinding::STATUS_CONFIGURED) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('docker', sprintf('Starting %s in Docker on %s', strtoupper($database->engine), $server->name));
            $result = $provisioner->provision($server, $site, $database);
            foreach (preg_split("/\r?\n/", $result['output']) ?: [] as $line) {
                if ($line !== '') {
                    $emit($line, ConsoleAction::LEVEL_INFO, 'docker');
                }
            }

            $config = is_array($binding->config) ? $binding->config : [];
            $config['docker_container'] = $result['container'];
            $config['docker_volume'] = 'dply-db-'.$result['container'];
            $config['host_port'] = $result['host_port'];
            $config['connection_ready_at'] = now()->toIso8601String();
            $binding->forceFill(['config' => $config])->save();

            $database->forceFill(['host' => '127.0.0.1'])->save();

            $manager->wireServerDatabaseBinding($binding->fresh() ?? $binding, $database->fresh() ?? $database, $site);

            if (strtolower((string) $site->runtime) === 'php' && $database->engine !== 'redis') {
                EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, (string) $database->engine);
            }

            $emit->success('docker', __('Docker database is ready.'));
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit($e->getMessage(), ConsoleAction::LEVEL_ERROR, 'docker');
            $binding->forceFill([
                'status' => SiteBinding::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ])->save();
            $this->failConsoleAction($e->getMessage());
        }
    }
}
