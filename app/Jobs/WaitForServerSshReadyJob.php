<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Support\Servers\ProvisionPipelineLog;
use App\Support\Servers\TcpPortProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Cloud APIs often return a public IP before sshd is accepting connections. This job waits
 * for TCP port 22 (or ssh_port) then dispatches {@see RunSetupScriptJob}.
 */
class WaitForServerSshReadyJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 45;

    public function __construct(
        public Server $server
    ) {
        $this->tries = max(5, (int) config('server_provision.ssh_ready_max_attempts', 45));
    }

    public function handle(): void
    {
        $server = $this->server->fresh();
        $attempt = $this->attempts();
        $maxAttempts = $this->tries;

        if ($server === null) {
            Log::debug('server.provision.ssh_ready.skip_missing_server', [
                'server_id' => $this->server->id,
                'attempt' => $attempt,
            ]);

            return;
        }

        if ($server->status !== Server::STATUS_READY || empty($server->ip_address)) {
            ProvisionPipelineLog::debug('server.provision.ssh_ready.skip_ineligible', $server, [
                'attempt' => $attempt,
                'server_status' => $server->status,
                'has_ip' => filled($server->ip_address),
            ]);

            return;
        }

        $retrySeconds = max(3, (int) config('server_provision.ssh_ready_retry_seconds', 8));

        $host = trim((string) $server->ip_address);
        $port = (int) ($server->ssh_port ?: 22);

        $logEvery = max(1, (int) config('server_provision.ssh_ready_log_every_n_attempts', 5));
        $shouldInfoThisAttempt = $attempt === 1
            || $attempt >= $maxAttempts
            || ($attempt % $logEvery === 0);

        if ($shouldInfoThisAttempt) {
            ProvisionPipelineLog::info('server.provision.ssh_ready.poll', $server, [
                'phase' => 'tcp_check',
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'host' => $host,
                'port' => $port,
                'retry_in_seconds' => $retrySeconds,
            ]);
        } else {
            ProvisionPipelineLog::debug('server.provision.ssh_ready.poll', $server, [
                'phase' => 'tcp_check',
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'host' => $host,
                'port' => $port,
            ]);
        }

        if (TcpPortProbe::isOpen($host, $port)) {
            ProvisionPipelineLog::info('server.provision.ssh_ready.open_dispatching_setup', $server, [
                'phase' => 'tcp_open',
                'attempt' => $attempt,
                'host' => $host,
                'port' => $port,
            ]);
            RunSetupScriptJob::dispatch($server);

            return;
        }

        if ($attempt >= $maxAttempts) {
            ProvisionPipelineLog::warning('server.provision.ssh_ready.max_attempts', $server, [
                'phase' => 'tcp_timeout',
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'host' => $host,
                'port' => $port,
                'detail' => 'SSH port did not become reachable; stack setup was not started.',
            ]);

            return;
        }

        ProvisionPipelineLog::debug('server.provision.ssh_ready.retry', $server, [
            'phase' => 'tcp_closed',
            'attempt' => $attempt,
            'next_attempt' => $attempt + 1,
            'release_seconds' => $retrySeconds,
            'host' => $host,
            'port' => $port,
        ]);

        $this->release($retrySeconds);
    }
}
