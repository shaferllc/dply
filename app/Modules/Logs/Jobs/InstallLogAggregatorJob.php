<?php

declare(strict_types=1);

namespace App\Modules\Logs\Jobs;

use App\Jobs\InstallLogAgentJob;

use App\Models\Server;
use App\Models\ServerLogAggregator;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\VectorLogAggregatorInstallScripts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Stands up (or re-syncs) the dply Logs Vector aggregator on the designated log box
 * over SSH. Sibling to {@see InstallLogAgentJob} (the edge side): flips the row to
 * `installing`, streams install output for live progress, then — on success —
 * captures the generated edge mTLS material in a SEPARATE call (so the client
 * private key never lands in the streamed install_output) and records it + the edge
 * endpoint on the row. The edge installer reads those to configure shipping with no
 * manual env. See docs/SERVER_LOGS_ADDON.md.
 */
class InstallLogAggregatorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(
        public string $serverLogAggregatorId,
    ) {
        $queue = config('server_logs.install_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'install-log-aggregator:'.$this->serverLogAggregatorId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        VectorLogAggregatorInstallScripts $scripts,
    ): void {
        /** @var ServerLogAggregator|null $aggregator */
        $aggregator = ServerLogAggregator::query()->with('server')->find($this->serverLogAggregatorId);
        if ($aggregator === null) {
            return;
        }

        $server = $aggregator->server;
        if (! $server instanceof Server || ! $server->isVmHost()) {
            $aggregator->update([
                'status' => ServerLogAggregator::STATUS_FAILED,
                'error_message' => 'Aggregator server is not a reachable VM host.',
            ]);

            return;
        }

        $server = $aggregator->server;

        $aggregator->update([
            'status' => ServerLogAggregator::STATUS_INSTALLING,
            'error_message' => null,
            'install_output' => '',
        ]);

        try {
            $buffer = '';
            $lastFlush = 0.0;
            $flush = function (bool $force = false) use ($aggregator, &$buffer, &$lastFlush): void {
                $now = microtime(true);
                if (! $force && ($now - $lastFlush) < 3.0) {
                    return;
                }
                $lastFlush = $now;
                $aggregator->update(['install_output' => mb_substr($buffer, -32_000)]);
            };

            $output = $executor->runInlineBashWithOutputCallback(
                $server,
                'log-aggregator:install',
                $scripts->installScript($aggregator),
                function (string $type, string $chunk) use (&$buffer, $flush): void {
                    $buffer .= $chunk;
                    $flush();
                },
                timeoutSeconds: 600,
                asRoot: true,
            );
            $flush(true);

            if ($output->exitCode !== 0) {
                throw new \RuntimeException(
                    Str::limit(trim($output->buffer), 800) ?: 'Aggregator install failed.'
                );
            }

            // Capture the generated edge mTLS material in a SEPARATE call whose
            // output is NOT streamed into install_output (it carries a private key).
            $material = $executor->runInlineBash(
                $server,
                'log-aggregator:fetch-material',
                $scripts->fetchEdgeMaterialScript(),
                timeoutSeconds: 60,
                asRoot: true,
            );

            $parsed = $this->parseMaterial($material->buffer);

            $aggregator->update([
                'status' => ServerLogAggregator::STATUS_RUNNING,
                'version' => $scripts->parseVersion($output->buffer),
                'endpoint' => $this->resolveEndpoint($aggregator),
                'private_endpoint' => $this->resolvePrivateEndpoint($aggregator),
                'edge_ca_cert_b64' => $parsed['ca'] ?? $aggregator->edge_ca_cert_b64,
                'edge_client_cert_b64' => $parsed['crt'] ?? $aggregator->edge_client_cert_b64,
                'edge_client_key_b64' => $parsed['key'] ?? $aggregator->edge_client_key_b64,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $aggregator->update([
                'status' => ServerLogAggregator::STATUS_FAILED,
                'error_message' => Str::limit($e->getMessage(), 800),
            ]);

            throw $e;
        }
    }

    /**
     * The address edges dial: the box's public IP + listen port.
     */
    protected function resolveEndpoint(ServerLogAggregator $aggregator): string
    {
        $ip = trim((string) $aggregator->server->ip_address);
        $port = $aggregator->listen_port > 0 ? $aggregator->listen_port : 6000;

        return $ip !== '' ? "{$ip}:{$port}" : '';
    }

    /**
     * The private (VPC) address, when the box has one. Same-network edges prefer
     * this over the public endpoint so log traffic stays off the public internet
     * and clear of any provider cloud-firewall on the listen port. Empty when the
     * box has no private IP — edges then just use the public endpoint.
     */
    protected function resolvePrivateEndpoint(ServerLogAggregator $aggregator): string
    {
        $ip = trim((string) ($aggregator->server->private_ip_address ?? ''));
        $port = $aggregator->listen_port > 0 ? $aggregator->listen_port : 6000;

        return $ip !== '' ? "{$ip}:{$port}" : '';
    }

    /**
     * Pull the DPLY_EDGE_*_B64 lines out of the handoff file contents.
     *
     * @return array{ca?:string,crt?:string,key?:string}
     */
    protected function parseMaterial(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (preg_match('/^DPLY_EDGE_CA_B64=(.+)$/', $line, $m) === 1) {
                $out['ca'] = trim($m[1]);
            } elseif (preg_match('/^DPLY_EDGE_CRT_B64=(.+)$/', $line, $m) === 1) {
                $out['crt'] = trim($m[1]);
            } elseif (preg_match('/^DPLY_EDGE_KEY_B64=(.+)$/', $line, $m) === 1) {
                $out['key'] = trim($m[1]);
            }
        }

        return $out;
    }
}
