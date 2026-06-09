<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * One-shot SSH probe of a long-running app server's localhost port — the
 * reverse-proxy target for Node/Rails/Python/Go sites (see
 * {@see Site::isLongRunningAppServer()}). Mirrors the binding reachability probe
 * (a pure-bash `/dev/tcp` connect, no extra tooling required), but aimed at
 * 127.0.0.1:{app_port} on the site's OWN server. Run deferred (wire:init),
 * never on the render path.
 */
final class SiteAppServerProbe
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array{listening: bool, port: int}|null
     */
    public function probe(Site $site): ?array
    {
        $server = $site->server;
        $port = (int) ($site->app_port ?? 0);
        if ($server === null || $port < 1 || $port > 65535) {
            return null;
        }

        // A bare TCP connect to the loopback port: succeeds only when the app
        // server is actually accepting connections there. `timeout` guards a
        // hung listener; the marker (not the exit code) is what we read, since
        // SshConnection::exec() never throws on non-zero.
        $script = <<<BASH
if timeout 3 bash -c '</dev/tcp/127.0.0.1/{$port}' >/dev/null 2>&1; then
  echo "listen=1"
else
  echo "listen=0"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'site-appserver-probe', $script, 20, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('sites.appserver_probe_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! str_contains($buffer, 'listen=1') && ! str_contains($buffer, 'listen=0')) {
            return null;
        }

        return [
            'listening' => str_contains($buffer, 'listen=1'),
            'port' => $port,
        ];
    }
}
