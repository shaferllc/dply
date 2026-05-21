<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\FunctionInvocation;
use App\Models\Site;

/**
 * Fire a single dply tick against a serverless function.
 *
 * Both the scheduled {@see \App\Console\Commands\ServerlessTickCommand}
 * (every minute, all enabled sites) and the in-UI "Tick now" buttons on the
 * Schedule / Workers pages delegate here, so a tick is recorded identically
 * however it was triggered.
 *
 * The tick goes through {@see FunctionInvoker}: an authenticated blocking
 * invoke that returns the activation inline and persists it as a
 * `source=tick` FunctionInvocation — runtime logs included. This replaced
 * the old `/web/` GET, which could only see a status code and a body
 * preview (and the `meta.serverless.tick_history` ring buffer it wrote).
 */
final class InvokeFunctionTick
{
    public function __construct(private readonly FunctionInvoker $invoker) {}

    /**
     * Tick a single task for the site and return the recorded invocation.
     *
     * `schedule` / `queue` run in command mode — the function needs the
     * signed `x-dply-run` header to put the adapter into scheduler / queue
     * mode. `keep-warm` is a plain request that just holds a warm container.
     *
     * Returns null only when the task can't run at all — a command-mode
     * tick with no webhook secret, or a host that isn't provisioned.
     */
    public function tickSite(Site $site, string $task): ?FunctionInvocation
    {
        $headers = [];

        if ($task === 'schedule' || $task === 'queue') {
            $secret = trim((string) $site->webhook_secret);
            if ($secret === '') {
                return null;
            }
            $headers = [
                'x-dply-run' => $task,
                'x-dply-secret' => $secret,
            ];
        }

        $result = $this->invoker->invoke($site, FunctionInvocation::SOURCE_TICK, $task, [
            '__ow_method' => 'get',
            '__ow_path' => '',
            '__ow_headers' => $headers,
            '__ow_query' => '',
        ]);

        return $result['invocation'];
    }
}
