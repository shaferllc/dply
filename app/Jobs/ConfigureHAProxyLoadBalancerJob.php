<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LoadBalancer;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\HAProxyConfigBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Write (or remove) the HAProxy config for a software load balancer and reload
 * the service over SSH. Called after creating the LB, adding/removing targets,
 * and deleting the LB.
 */
class ConfigureHAProxyLoadBalancerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $loadBalancerId,
        public bool $remove = false,
    ) {}

    public function handle(ExecuteRemoteTaskOnServer $executor): void
    {
        $lb = LoadBalancer::query()
            ->with(['server', 'targets.server', 'services'])
            ->find($this->loadBalancerId);

        if (! $lb || ! $lb->server) {
            return;
        }

        if ($this->remove) {
            $script = HAProxyConfigBuilder::removeScript($lb);
        } else {
            $backends = $lb->targets
                ->map(fn ($t) => $t->server)
                ->filter()
                ->map(fn ($s) => [
                    'name' => $s->name,
                    'ip' => $s->private_ip_address ?? $s->ip_address ?? '127.0.0.1',
                    'port' => 80,
                ])
                ->values()
                ->all();

            $services = $lb->services->map(fn ($svc) => [
                'protocol' => $svc->protocol,
                'listen_port' => $svc->listen_port,
                'destination_port' => $svc->destination_port,
            ])->all();

            $script = HAProxyConfigBuilder::applyScript($lb, $backends, $services);
        }

        $output = $executor->runInlineBash(
            $lb->server,
            'haproxy:configure:'.$lb->id,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            $lb->update([
                'status' => LoadBalancer::STATUS_ERROR,
                'error_message' => Str::limit(trim($output->buffer), 800) ?: 'HAProxy config failed.',
            ]);

            return;
        }

        if (! $this->remove) {
            $lb->update([
                'status' => LoadBalancer::STATUS_RUNNING,
                'error_message' => null,
                'public_ipv4' => $lb->server->ip_address,
                'private_ip' => $lb->server->private_ip_address,
            ]);
        }
    }
}
