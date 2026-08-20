<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Console\ServerlessTickCommand;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Support\ServerlessArtisan;

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
     *
     * @param  array<string, string>  $extraHeaders  pump tuning for a queue slot
     */
    public function tickSite(Site $site, string $task, array $extraHeaders = []): ?FunctionInvocation
    {
        $headers = [];

        if (in_array($task, ['schedule', 'queue', 'queue-retry', 'artisan'], true)) {
            $headers = [
                'x-dply-run' => $task,
                'x-dply-secret' => $site->ensureServerlessCommandSecret(),
            ];
        }

        $result = $this->invoker->invoke($site, FunctionInvocation::SOURCE_TICK, $task, [
            '__ow_method' => 'get',
            '__ow_path' => '',
            '__ow_headers' => array_merge($headers, $extraHeaders),
            '__ow_query' => '',
        ]);

        return $result['invocation'];
    }

    /**
     * Run one pump queue slot and return the handler's report alongside the
     * recorded invocation.
     *
     * The handler answers in JSON ({@see dply_do_functions_queue_slot}); a
     * body that isn't that shape means the function is running a handler
     * from before the pump existed. That reports `remaining` as null, which
     * the pump reads as "assume more work" — the safe direction, and it
     * degrades to roughly the old drain-until-empty behaviour rather than
     * silently processing nothing.
     *
     * @param  array{queue: string, slot_max_time: int, slot_max_jobs: int}  $options
     * @return array{invocation: ?FunctionInvocation, report: array<string, mixed>}
     */
    public function tickQueueSlot(Site $site, array $options): array
    {
        $headers = ['x-dply-queue-max-time' => (string) $options['slot_max_time']];

        if ($options['slot_max_jobs'] > 0) {
            $headers['x-dply-queue-max-jobs'] = (string) $options['slot_max_jobs'];
        }

        $queue = trim($options['queue']);
        if ($queue !== '') {
            $headers['x-dply-queue'] = $queue;
        }

        $invocation = $this->tickSite($site, 'queue', $headers);

        return [
            'invocation' => $invocation,
            'report' => $this->parseReport($invocation),
        ];
    }

    /**
     * Push failed jobs back onto the function's queue.
     *
     * `all`, or a specific Laravel failed-job uuid. A function has no CLI, so
     * this is the only way to run `queue:retry` against one.
     *
     * @return array{ok: bool, output: string}
     */
    public function retryFailedJobs(Site $site, string $id = 'all'): array
    {
        $invocation = $this->tickSite($site, 'queue-retry', [
            'x-dply-queue-retry-id' => $id,
        ]);

        return [
            'ok' => $invocation !== null && (bool) $invocation->success,
            'output' => trim((string) ($invocation->result_excerpt ?? '')),
        ];
    }

    /**
     * Run one allowlisted artisan command, HMAC-signed so a captured tick
     * cannot be turned into arbitrary remote execution.
     *
     * `down` / `up` also persist durable maintenance (bound parameter + env)
     * because `/tmp` `artisan down` is lost on the next cold start.
     */
    public function runArtisan(Site $site, string $command): ?FunctionInvocation
    {
        $command = trim($command);
        [$name] = ServerlessArtisan::parse($command);

        if (in_array($name, ['down', 'up'], true)) {
            app(ServerlessMaintenance::class)->setEnabled($site, $name === 'down');
        }

        $secret = $site->ensureServerlessCommandSecret();

        return $this->tickSite($site, 'artisan', [
            'x-dply-artisan' => $command,
            'x-dply-signature' => ServerlessArtisan::signature($secret, $command),
        ]);
    }

    /**
     * @return array{ok: bool, processed: int, failed: int, failures: list<array<string, mixed>>, remaining: ?int}
     */
    private function parseReport(?FunctionInvocation $invocation): array
    {
        $unknown = ['ok' => false, 'processed' => 0, 'failed' => 0, 'failures' => [], 'remaining' => null];

        if ($invocation === null) {
            return $unknown;
        }

        $decoded = json_decode((string) $invocation->result_excerpt, true);
        if (! is_array($decoded) || ($decoded['dply_queue_slot'] ?? null) !== true) {
            return array_merge($unknown, ['ok' => (bool) $invocation->success]);
        }

        $failures = is_array($decoded['failures'] ?? null) ? $decoded['failures'] : [];

        return [
            'ok' => (bool) ($decoded['ok'] ?? false),
            'processed' => max(0, (int) ($decoded['processed'] ?? 0)),
            'failed' => max(0, (int) ($decoded['failed'] ?? 0)),
            'failures' => array_values(array_filter($failures, 'is_array')),
            'remaining' => array_key_exists('remaining', $decoded) && $decoded['remaining'] !== null
                ? max(0, (int) $decoded['remaining'])
                : null,
        ];
    }
}
