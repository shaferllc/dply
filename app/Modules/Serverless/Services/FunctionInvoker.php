<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Support\ActivationRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Invokes a DigitalOcean Functions action through the authenticated,
 * blocking management API — and records the result as a FunctionInvocation.
 *
 * This is dply's only reliable window into a function's runtime. The DO
 * activations *list* API is structurally empty (proven: a blocking invoke
 * succeeds yet `GET /activations?count=true` returns 0). But a blocking
 * invoke against `POST /namespaces/_/actions/{action}` returns the entire
 * activation inline — id, logs, server-measured duration, cold-start
 * annotation, response. So dply captures logs by being the caller.
 *
 * Used by background ticks (source=tick) and the Logs-page test button
 * (source=test). Organic web traffic never comes through here — the
 * function handler reports that to the ingest endpoint instead.
 */
class FunctionInvoker
{
    /** Cap the stored result excerpt so a large HTML body can't bloat a row. */
    private const RESULT_EXCERPT_BYTES = 2000;

    /**
     * Invoke the site's function and persist a FunctionInvocation row.
     *
     * `$owArgs` is the raw OpenWhisk web-action event the handler will see —
     * `__ow_method`, `__ow_path`, `__ow_headers`, `__ow_query`, `__ow_body`.
     * The caller owns it so ticks can inject the `x-dply-run` command header
     * and the test button can replay an operator-chosen method/path.
     *
     * @param  array<string, mixed>  $owArgs
     * @return array{ok: bool, error: ?string, invocation: ?FunctionInvocation}
     */
    public function invoke(Site $site, string $source, ?string $task, array $owArgs): array
    {
        $site->loadMissing('server');
        $server = $site->server;

        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return ['ok' => false, 'error' => 'This site is not a functions host.', 'invocation' => null];
        }

        $cfg = is_array($server->meta['digitalocean_functions'] ?? null) ? $server->meta['digitalocean_functions'] : [];
        $apiHost = rtrim((string) ($cfg['api_host'] ?? ''), '/');
        $accessKey = (string) ($cfg['access_key'] ?? '');
        $actionName = $this->actionName($site);

        if ($apiHost === '' || ! str_contains($accessKey, ':') || $actionName === '') {
            return ['ok' => false, 'error' => 'The function host is not provisioned yet.', 'invocation' => null];
        }

        // Mark the event as dply-initiated so the handler skips its organic
        // ingest POST — dply already captures this invocation inline here.
        $headers = is_array($owArgs['__ow_headers'] ?? null) ? $owArgs['__ow_headers'] : [];
        $headers['x-dply-source'] = $source;
        $owArgs['__ow_headers'] = $headers;

        [$keyId, $keySecret] = explode(':', $accessKey, 2);
        $endpoint = $apiHost.'/api/v1/namespaces/_/actions/'.rawurlencode($actionName)
            .'?blocking=true&result=false';

        try {
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->acceptJson()
                ->timeout(75)
                ->post($endpoint, $owArgs);
        } catch (Throwable $e) {
            // A transient network failure still gets a row — an invisible
            // failed tick is worse than a visible one.
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'invocation' => $this->recordFailure($site, $source, $task, $owArgs, $e->getMessage(), null),
            ];
        }

        $activation = is_array($response->json()) ? $response->json() : [];

        if (! $response->successful() && $activation === []) {
            $error = 'Functions API returned HTTP '.$response->status().'.';

            return [
                'ok' => false,
                'error' => $error,
                'invocation' => $this->recordFailure($site, $source, $task, $owArgs, $error, $response->status()),
            ];
        }

        $invocation = $this->record($site, $source, $task, $owArgs, $activation);

        return ['ok' => true, 'error' => null, 'invocation' => $invocation];
    }

    /**
     * Record a row for an invocation that never reached the function — a
     * timeout, DNS failure, or a gateway error with no activation body.
     *
     * @param  array<string, mixed>  $owArgs
     */
    private function recordFailure(Site $site, string $source, ?string $task, array $owArgs, string $error, ?int $status): FunctionInvocation
    {
        return FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => $source,
            // The invocation never reached the function — there is no
            // activation to collect, now or later.
            'state' => FunctionInvocation::STATE_FAILED,
            'task' => $task,
            'method' => strtoupper((string) ($owArgs['__ow_method'] ?? 'GET')),
            'path' => '/'.ltrim((string) ($owArgs['__ow_path'] ?? ''), '/'),
            'status_code' => $status,
            'success' => false,
            'duration_ms' => 0,
            'cold' => false,
            'activation_id' => null,
            'log_lines' => [],
            'result_excerpt' => Str::limit($error, self::RESULT_EXCERPT_BYTES),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Persist one activation as a FunctionInvocation row.
     *
     * The record is parsed by {@see ActivationRecord} — the same parser the
     * async poller uses, so a blocking and a polled invocation of the same
     * function produce identical rows.
     *
     * @param  array<string, mixed>  $owArgs
     * @param  array<string, mixed>  $activation
     */
    private function record(Site $site, string $source, ?string $task, array $owArgs, array $activation): FunctionInvocation
    {
        return FunctionInvocation::query()->create(array_merge([
            'site_id' => $site->id,
            'source' => $source,
            'task' => $task,
            'method' => strtoupper((string) ($owArgs['__ow_method'] ?? 'GET')),
            'path' => '/'.ltrim((string) ($owArgs['__ow_path'] ?? ''), '/'),
            'created_at' => Carbon::now(),
        ], ActivationRecord::fromArray($activation)->toRowAttributes()));
    }

    private function actionName(Site $site): string
    {
        return $site->serverlessActionName();
    }
}
