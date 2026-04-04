<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RemoveSiteRepositoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $siteId
    ) {}

    public function handle(SshConnectionFactory $sshFactory): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isReady() || empty($server->ssh_private_key)) {
            return;
        }

        if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            return;
        }

        $base = rtrim($site->effectiveRepositoryPath(), '/');
        if ($base === '' || $base === '/') {
            return;
        }

        $ssh = $sshFactory->forServer($server);
        $strategy = (string) ($site->deploy_strategy ?? 'simple');
        $baseEsc = escapeshellarg($base);

        if ($strategy === 'atomic') {
            $log = $ssh->exec(sprintf(
                'rm -rf %1$s/releases 2>/dev/null; rm -f %1$s/current 2>/dev/null; find %1$s -mindepth 1 -maxdepth 1 ! -name ".dply" -exec rm -rf {} + 2>/dev/null; printf "\nDPLY_RM_EXIT:%%s" "$?"',
                $baseEsc
            ), 600);
        } else {
            $log = $ssh->exec(sprintf('rm -rf %s 2>&1; printf "\nDPLY_RM_EXIT:%%s" "$?"', $baseEsc), 600);
        }

        Log::info('RemoveSiteRepositoryJob finished', ['site_id' => $site->id, 'tail' => substr($log, -500)]);
    }
}
