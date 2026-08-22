<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessRuntimeSettings;
use App\Modules\Serverless\Support\FunctionConfiguration;
use App\Modules\Serverless\Support\FunctionCorsPolicy;
use App\Modules\Serverless\Support\FunctionLogForwarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The workspace **Runtime** tab over HTTP: resource limits, HTTP exposure
 * (web mode, endpoint secret, CORS), bound parameters, log forwarding,
 * maintenance, and warm start.
 *
 * One `PATCH` covers the whole tab, and anything absent from the body is left
 * alone — the tab saves in three forms, but a CLI that had to mirror that
 * split would just be repeating an accident of the layout. Writes go through
 * {@see ServerlessRuntimeSettings}, the same owner the page uses, so a change
 * made here pushes to the live action exactly as a save in the UI does.
 */
class ServerlessRuntimeApiController extends ServerlessApiController
{
    public function __construct(private readonly ServerlessRuntimeSettings $settings) {}

    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->state($found)]);
    }

    public function update(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'memory_mb' => ['sometimes', 'integer', Rule::in(Site::SERVERLESS_MEMORY_OPTIONS_MB)],
            'timeout_ms' => ['sometimes', 'integer', 'min:'.Site::SERVERLESS_MIN_TIMEOUT_MS, 'max:'.Site::SERVERLESS_MAX_TIMEOUT_MS],
            'concurrency' => ['sometimes', 'integer', 'min:1', 'max:'.Site::SERVERLESS_MAX_CONCURRENCY],
            'logs_kb' => ['sometimes', 'integer', 'min:'.Site::SERVERLESS_MIN_LOGS_KB, 'max:'.Site::SERVERLESS_MAX_LOGS_KB],

            'web_mode' => ['sometimes', 'string', Rule::in(FunctionConfiguration::MODES)],
            'secured' => ['sometimes', 'boolean'],
            'provide_api_key' => ['sometimes', 'boolean'],

            'cors' => ['sometimes', 'array'],
            'cors.enabled' => ['sometimes', 'boolean'],
            'cors.allow_origins' => ['sometimes', 'array'],
            'cors.allow_origins.*' => ['string', 'max:2000'],
            'cors.allow_methods' => ['sometimes', 'array'],
            'cors.allow_methods.*' => ['string', Rule::in(FunctionCorsPolicy::METHODS)],
            'cors.allow_headers' => ['sometimes', 'array'],
            'cors.allow_headers.*' => ['string', 'max:2000'],
            'cors.allow_credentials' => ['sometimes', 'boolean'],
            'cors.max_age' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],

            // A whole-map replace: send every parameter you want the function
            // to keep, the same as saving the tab's table.
            'parameters' => ['sometimes', 'array', 'max:100'],
            'parameters.*' => ['string', 'max:8000'],
            'parameters_final' => ['sometimes', 'boolean'],

            'log_forwarding' => ['sometimes', 'array'],
            'log_forwarding.provider' => ['sometimes', 'string', Rule::in(array_merge([''], FunctionLogForwarding::PROVIDERS))],
            'log_forwarding.token' => ['sometimes', 'string', 'max:512'],
            'log_forwarding.endpoint' => ['sometimes', 'string', 'max:512'],

            'maintenance' => ['sometimes', 'boolean'],
            'keep_warm' => ['sometimes', 'boolean'],
        ]);

        foreach (array_keys($data['parameters'] ?? []) as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', (string) $name) !== 1) {
                return response()->json([
                    'message' => "Parameter name [{$name}] must start with a letter or underscore and contain only letters, digits, underscores, dots, and hyphens.",
                ], 422);
            }
        }

        if ($this->touchesLimits($data)) {
            $current = $this->settings->snapshot($found)['limits'];

            $this->settings->saveLimits($found, [
                'memory_mb' => (int) ($data['memory_mb'] ?? $current['memory_mb']),
                'timeout_ms' => (int) ($data['timeout_ms'] ?? $current['timeout_ms']),
                'concurrency' => (int) ($data['concurrency'] ?? $current['concurrency']),
                'logs_kb' => (int) ($data['logs_kb'] ?? $current['logs_kb']),
            ]);
        }

        $result = null;

        if ($this->touchesHttp($data)) {
            $result = $this->settings->saveHttpConfig($found, $this->mergedHttpInput($found, $data));

            if (! $result['ok']) {
                return response()->json([
                    'message' => $result['error'] ?? 'The host rejected the configuration.',
                ], 422);
            }
        }

        if (array_key_exists('maintenance', $data)) {
            if (! $found->isLaravelFrameworkDetected()) {
                return response()->json(['message' => 'Maintenance mode applies to Laravel functions only.'], 422);
            }

            $maintenance = $this->settings->setMaintenance($found, (bool) $data['maintenance']);

            if (! $maintenance['ok']) {
                return response()->json([
                    'message' => $maintenance['error'] ?? 'The host rejected the maintenance update.',
                ], 422);
            }

            $result = $maintenance;
        }

        if (array_key_exists('keep_warm', $data)) {
            $this->settings->setKeepWarm($found, (bool) $data['keep_warm']);
        }

        return response()->json([
            'data' => $this->state($found->fresh()) + [
                // Limits and a never-deployed function wait for a deploy;
                // metadata pushed to the live action does not.
                'applied' => $result === null ? false : $result['applied'],
            ],
        ]);
    }

    /** Replace the endpoint secret — every existing caller starts getting 401s. */
    public function rotateSecret(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $result = $this->settings->rotateEndpointSecret($found);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error'] ?? 'Could not rotate the endpoint secret.'], 422);
        }

        return response()->json(['data' => ['rotated' => true, 'applied' => $result['applied']]]);
    }

    /**
     * The snapshot plus what the tab shows around it: whether saved limits are
     * still waiting on a deploy to reach the action.
     *
     * @return array<string, mixed>
     */
    private function state(Site $site): array
    {
        $snapshot = $this->settings->snapshot($site);
        $deployed = $site->serverlessConfig()['deployed_limits'] ?? null;

        $snapshot['limits']['pending_redeploy'] = is_array($deployed) && [
            'memory' => $snapshot['limits']['memory_mb'],
            'timeout' => $snapshot['limits']['timeout_ms'],
            'concurrency' => $snapshot['limits']['concurrency'],
            'logs' => $snapshot['limits']['logs_kb'],
        ] !== Site::normalizeServerlessLimits($deployed);

        return $snapshot;
    }

    /**
     * Fill a partial HTTP patch from what is stored — `saveHttpConfig` writes
     * the block whole, so anything the caller left out must come from the
     * current state rather than a default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergedHttpInput(Site $site, array $data): array
    {
        $current = $this->settings->snapshot($site);
        $http = $current['http'];
        $cors = $http['cors'];
        $patch = (array) ($data['cors'] ?? []);
        $forwarding = (array) ($data['log_forwarding'] ?? []);

        return [
            'web_mode' => $data['web_mode'] ?? $http['web_mode'],
            'secured' => $data['secured'] ?? $http['secured'],
            'provide_api_key' => $data['provide_api_key'] ?? $http['provide_api_key'],
            'cors' => [
                'enabled' => $patch['enabled'] ?? $cors['enabled'],
                'allow_origins' => $patch['allow_origins'] ?? $cors['allow_origins'],
                'allow_methods' => $patch['allow_methods'] ?? $cors['allow_methods'],
                'allow_headers' => $patch['allow_headers'] ?? $cors['allow_headers'],
                'allow_credentials' => $patch['allow_credentials'] ?? $cors['allow_credentials'],
                'max_age' => array_key_exists('max_age', $patch) ? $patch['max_age'] : $cors['max_age'],
            ],
            'parameters' => $data['parameters'] ?? $current['parameters'],
            'parameters_final' => $data['parameters_final'] ?? $current['parameters_final'],
            'log_forwarding' => [
                'provider' => $forwarding['provider'] ?? $current['log_forwarding']['provider'],
                // Tokens are never read back, so a patch that doesn't carry one
                // must reuse what is stored instead of blanking it.
                'token' => $forwarding['token'] ?? $this->storedLogToken($site),
                'endpoint' => $forwarding['endpoint'] ?? $current['log_forwarding']['endpoint'],
            ],
        ];
    }

    private function storedLogToken(Site $site): string
    {
        return FunctionLogForwarding::fromArray(
            (array) ($site->serverlessConfig()['log_forwarding'] ?? []),
        )->token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function touchesLimits(array $data): bool
    {
        return (bool) array_intersect(['memory_mb', 'timeout_ms', 'concurrency', 'logs_kb'], array_keys($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function touchesHttp(array $data): bool
    {
        return (bool) array_intersect(
            ['web_mode', 'secured', 'provide_api_key', 'cors', 'parameters', 'parameters_final', 'log_forwarding'],
            array_keys($data),
        );
    }
}
