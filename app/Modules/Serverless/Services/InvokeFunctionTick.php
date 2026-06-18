<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Modules\Serverless\Console\ServerlessTickCommand;
use App\Models\FunctionInvocation;
use App\Models\Site;

/**
 * Fire a single dply tick against a serverless function.
 *
 * Both the scheduled {@see ServerlessTickCommand}
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
     * The command header is signed with the site's stable serverless command
     * secret — minted once and baked into the deployed function's env by the
     * environment preparer — never the operator-rotatable webhook_secret.
     */
    public function tickSite(Site $site, string $task): ?FunctionInvocation
    {
        $headers = [];

        if ($task === 'schedule' || $task === 'queue') {
            $headers = [
                'x-dply-run' => $task,
                'x-dply-secret' => $site->ensureServerlessCommandSecret(),
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
