<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\FunctionInvoker;
use App\Modules\Serverless\Services\FunctionScheduleService;
use App\Modules\Serverless\Services\OpenWhiskClient;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace **Platform** tab over HTTP: what is actually deployed on the
 * functions host, and a way to poke it.
 *
 * Read paths mirror the tab's Inspector and Triggers panels — everything comes
 * live from {@see OpenWhiskClient}, which is the source of truth, so the CLI
 * cannot drift from the platform any more than the UI can. `invoke` is the
 * Console panel: a real request against the function, recorded as a
 * `source=test` invocation so it shows up in `dply serverless invocations`.
 */
class ServerlessPlatformApiController extends ServerlessApiController
{
    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $client = new OpenWhiskClient($found->server);
        $actionName = $this->actionName($found);
        $action = $client->action($actionName);
        $actionDoc = ($action['ok'] && is_array($action['data'])) ? $action['data'] : null;

        return response()->json([
            'data' => [
                'action_name' => $actionName,
                'action' => $actionDoc === null ? null : [
                    'version' => $actionDoc['version'] ?? null,
                    'runtime' => data_get($actionDoc, 'exec.kind'),
                    'entry' => data_get($actionDoc, 'exec.main', 'main'),
                    'binary' => (bool) data_get($actionDoc, 'exec.binary', false),
                    'memory_mb' => (int) data_get($actionDoc, 'limits.memory', 0),
                    'timeout_ms' => (int) data_get($actionDoc, 'limits.timeout', 0),
                    'concurrency' => (int) data_get($actionDoc, 'limits.concurrency', 1),
                    'log_limit_mb' => (int) data_get($actionDoc, 'limits.logs', 0),
                    'published' => (bool) ($actionDoc['publish'] ?? false),
                    'web_export' => $this->annotation($actionDoc, 'web-export') === true,
                    // Code arrives base64-encoded; report the decoded size, the
                    // same arithmetic the Inspector shows.
                    'code_bytes' => (int) round(strlen((string) data_get($actionDoc, 'exec.code', '')) * 0.75),
                ],
                'error' => $action['ok'] ? null : ($action['error'] ?? 'Could not read the action from the functions host.'),
                'namespace' => [
                    'actions' => $this->names($client->actions()),
                    'packages' => $this->names($client->packages()),
                    'triggers' => $this->names($client->triggers()),
                    'rules' => $this->names($client->rules()),
                ],
            ],
        ]);
    }

    /** Cron triggers, plus the raw trigger/rule inventory behind them. */
    public function schedules(Request $request, string $site, FunctionScheduleService $schedules): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $result = $schedules->list($found);
        $client = new OpenWhiskClient($found->server);

        return response()->json([
            'data' => [
                'schedules' => $result['ok'] ? $result['triggers'] : [],
                'error' => $result['ok'] ? null : ($result['error'] ?? null),
                'triggers' => $this->names($client->triggers()),
                'rules' => $this->names($client->rules()),
            ],
        ]);
    }

    /** Send a real request at the function — the Console panel. */
    public function invoke(Request $request, string $site, FunctionInvoker $invoker): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'method' => ['nullable', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,get,post,put,patch,delete,head'],
            'path' => ['nullable', 'string', 'max:2048'],
            'body' => ['nullable', 'string', 'max:65536'],
            'query' => ['nullable', 'string', 'max:2048'],
            'headers' => ['array'],
            'headers.*' => ['string', 'max:1024'],
        ]);

        $result = $invoker->invoke($found, FunctionInvocation::SOURCE_TEST, null, [
            '__ow_method' => strtoupper((string) ($data['method'] ?? 'GET')),
            '__ow_path' => ltrim(trim((string) ($data['path'] ?? '')), '/'),
            '__ow_headers' => (array) ($data['headers'] ?? []),
            '__ow_body' => (string) ($data['body'] ?? ''),
            '__ow_query' => (string) ($data['query'] ?? ''),
        ]);

        $invocation = $result['invocation'];

        if (! $result['ok'] && $invocation === null) {
            return response()->json(['message' => $result['error'] ?? 'Invocation failed.'], 422);
        }

        return response()->json([
            'data' => [
                'id' => $invocation?->id,
                'ok' => (bool) $result['ok'],
                'success' => (bool) ($invocation->success ?? false),
                'status_code' => $invocation?->status_code,
                'duration_ms' => (int) ($invocation->duration_ms ?? 0),
                'excerpt' => $invocation?->result_excerpt,
                'logs' => $invocation?->logLines() ?? [],
                'error' => $result['error'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result  an OpenWhisk {ok, error, data} envelope
     * @return list<string>
     */
    private function names(array $result): array
    {
        if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            return [];
        }

        $names = [];

        foreach ($result['data'] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $actionDoc
     */
    private function annotation(array $actionDoc, string $key): mixed
    {
        foreach ((array) ($actionDoc['annotations'] ?? []) as $annotation) {
            if (is_array($annotation) && ($annotation['key'] ?? null) === $key) {
                return $annotation['value'] ?? null;
            }
        }

        return null;
    }

    private function actionName(Site $site): string
    {
        $config = $site->serverlessConfig();
        $name = trim((string) ($config['action_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $url = trim((string) ($config['action_url'] ?? ''));

        return $url === '' ? '' : basename(parse_url($url, PHP_URL_PATH) ?: '');
    }
}
