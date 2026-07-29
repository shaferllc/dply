<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
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
     * @param  array<string, mixed> $owArgs
     * @return array{ok: bool, error: ?string, invocation: ?FunctionInvocation}
     */
    /** @return array<string, mixed> */
    public function invoke(Site $site, string $source, ?string $task, array $owArgs): array
    {
        $site->loadMissing('server');
        $server = $site->server;

        if (! $server instanceof Server || ! $server->isDigitalOceanFunctionsHost()) {
            return ['ok' => false, 'error' => 'This site is not a DigitalOcean Functions host.', 'invocation' => null];
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
     * @param  array<string, mixed> $owArgs
     */
    private function recordFailure(Site $site, string $source, ?string $task, array $owArgs, string $error, ?int $status): FunctionInvocation
    {
        return FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => $source,
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
     * @param  array<string, mixed> $owArgs
     * @param  array<string, mixed> $activation
     */
    private function record(Site $site, string $source, ?string $task, array $owArgs, array $activation): FunctionInvocation
    {
        // OpenWhisk records an `initTime` annotation only on a cold start.
        $cold = false;
        foreach ((array) data_get($activation, 'annotations', []) as $annotation) {
            if (is_array($annotation) && ($annotation['key'] ?? null) === 'initTime'
                && (int) ($annotation['value'] ?? 0) > 0) {
                $cold = true;
                break;
            }
        }

        $result = data_get($activation, 'response.result');
        // The handler returns {statusCode, headers, body}; prefer that as the
        // HTTP status, falling back to OpenWhisk's own success/error split.
        $statusCode = (int) (data_get($activation, 'response.result.statusCode')
            ?? (data_get($activation, 'response.success') === true ? 200 : 500));

        return FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => $source,
            'task' => $task,
            'method' => strtoupper((string) ($owArgs['__ow_method'] ?? 'GET')),
            'path' => '/'.ltrim((string) ($owArgs['__ow_path'] ?? ''), '/'),
            'status_code' => $statusCode > 0 ? $statusCode : null,
            'success' => (bool) data_get($activation, 'response.success', false),
            'duration_ms' => (int) ($activation['duration'] ?? 0),
            'cold' => $cold,
            'activation_id' => (string) ($activation['activationId'] ?? '') ?: null,
            'log_lines' => array_values(array_filter((array) data_get($activation, 'logs', []), 'is_string')),
            'result_excerpt' => $this->excerpt($result),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * A bounded, human-readable excerpt of the activation result. The handler
     * returns {statusCode, headers, body}; the body is the useful part, so
     * prefer it and fall back to the whole result for any other shape.
     */
    private function excerpt(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        if (is_array($result) && isset($result['body']) && is_string($result['body'])) {
            return Str::limit($result['body'], self::RESULT_EXCERPT_BYTES);
        }

        $text = is_string($result)
            ? $result
            : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return Str::limit($text, self::RESULT_EXCERPT_BYTES);
    }

    private function actionName(Site $site): string
    {
        $cfg = $site->serverlessConfig();
        $name = trim((string) ($cfg['action_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        // Fall back to the trailing segment of the deployed web URL.
        $url = trim((string) ($cfg['action_url'] ?? ''));

        return $url === '' ? '' : basename(rtrim($url, '/'));
    }
}
