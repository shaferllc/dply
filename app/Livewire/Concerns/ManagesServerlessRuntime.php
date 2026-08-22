<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessRuntimeSettings;
use App\Modules\Serverless\Support\FunctionConfiguration;
use App\Modules\Serverless\Support\FunctionCorsPolicy;
use App\Modules\Serverless\Support\FunctionLogForwarding;
use Illuminate\Validation\Rule;

/**
 * Serverless function resource-limit and HTTP-exposure editing for the
 * Runtime tab.
 *
 * Memory / timeout / concurrency map onto the OpenWhisk action `limits`
 * block DigitalOcean Functions runs on. They are persisted to
 * meta.serverless.limits and pushed to the action by the deployer on the
 * next deploy — there is no live action-update path, so the UI tells the
 * operator when saved limits are pending a redeploy.
 *
 * The HTTP-exposure settings (web mode, CORS, endpoint secret, bound
 * parameters) are different: they are action metadata, not code, so
 * {@see ServerlessFunctionConfigurator} pushes them to the live action on
 * save and they only fall back to next-deploy when the function has never
 * been deployed.
 *
 * The host component (Sites\Show and its subclasses) provides $site,
 * authorize(), validate(), and the toast helpers. The writes themselves live
 * in {@see ServerlessRuntimeSettings}, shared with the HTTP API so the CLI
 * cannot drift from this tab.
 */
trait ManagesServerlessRuntime
{
    public int $serverless_memory = Site::SERVERLESS_DEFAULT_MEMORY_MB;

    public int $serverless_timeout_ms = Site::SERVERLESS_DEFAULT_TIMEOUT_MS;

    public int $serverless_concurrency = Site::SERVERLESS_DEFAULT_CONCURRENCY;

    public int $serverless_logs_kb = Site::SERVERLESS_DEFAULT_LOGS_KB;

    /** off | web | raw — how the function is reachable over HTTP. */
    public string $serverless_web_mode = FunctionConfiguration::MODE_WEB;

    /** Require a shared secret in X-Require-Whisk-Auth on every request. */
    public bool $serverless_secured = false;

    /** Pass the platform's own auth key through to the handler. */
    public bool $serverless_provide_api_key = false;

    public bool $serverless_cors_enabled = false;

    /** Comma/newline separated — parsed into a list on save. */
    public string $serverless_cors_origins = '*';

    /** @var list<string> */
    public array $serverless_cors_methods = ['GET', 'POST', 'OPTIONS'];

    public string $serverless_cors_headers = 'Content-Type, Authorization';

    public bool $serverless_cors_credentials = false;

    /**
     * Seconds, as typed. Kept a string rather than ?int because the field is
     * legitimately blank ("omit the header"), and an empty number input
     * hydrating into a typed int property is a fight not worth having.
     */
    public string $serverless_cors_max_age = '';

    /**
     * Default parameters bound to the function, as editable rows.
     *
     * @var list<array{key: string, value: string}>
     */
    public array $serverless_parameters = [];

    /** Seal bound parameters so a caller cannot override them per request. */
    public bool $serverless_parameters_final = false;

    /** Durable maintenance for Laravel functions (bound `__dply_maintenance`). */
    public bool $serverless_maintenance = false;

    /** '' | papertrail | datadog | logtail — where console logs are forwarded. */
    public string $serverless_log_provider = '';

    public string $serverless_log_token = '';

    /** Datadog only — the regional intake host. */
    public string $serverless_log_endpoint = '';

    /**
     * Hydrate the form fields from the site's stored limits. Called from
     * Show::syncFormFromSite() so the Runtime tab opens pre-filled.
     */
    public function syncServerlessRuntimeFromSite(): void
    {
        $limits = Site::normalizeServerlessLimits($this->site->serverlessLimits());
        $this->serverless_memory = $limits['memory'];
        $this->serverless_timeout_ms = $limits['timeout'];
        $this->serverless_concurrency = $limits['concurrency'];
        $this->serverless_logs_kb = $limits['logs'];

        $configuration = FunctionConfiguration::fromSiteConfig($this->site->serverlessConfig());

        $this->serverless_web_mode = $configuration->webMode;
        $this->serverless_secured = $configuration->isSecured();
        $this->serverless_provide_api_key = $configuration->provideApiKey;

        $cors = $configuration->cors;
        $this->serverless_cors_enabled = $cors->enabled;
        $this->serverless_cors_origins = implode(', ', $cors->allowOrigins);
        $this->serverless_cors_methods = $cors->allowMethods;
        $this->serverless_cors_headers = implode(', ', $cors->allowHeaders);
        $this->serverless_cors_credentials = $cors->allowCredentials;
        $this->serverless_cors_max_age = $cors->maxAge === null ? '' : (string) $cors->maxAge;

        $forwarding = $configuration->logForwarding;
        $this->serverless_log_provider = $forwarding->provider;
        $this->serverless_log_token = $forwarding->token;
        $this->serverless_log_endpoint = $forwarding->endpoint;

        $this->serverless_parameters_final = $configuration->finalParameters;
        $this->serverless_maintenance = $configuration->maintenance;
        $this->serverless_parameters = [];
        foreach ($configuration->parameters as $key => $value) {
            $this->serverless_parameters[] = [
                'key' => (string) $key,
                'value' => is_scalar($value) ? (string) $value : (string) json_encode($value),
            ];
        }
    }

    /** Reads and writes of runtime settings are shared with the HTTP API. */
    private function runtimeSettings(): ServerlessRuntimeSettings
    {
        return app(ServerlessRuntimeSettings::class);
    }

    public function addServerlessParameter(): void
    {
        $this->serverless_parameters[] = ['key' => '', 'value' => ''];
    }

    public function removeServerlessParameter(int $index): void
    {
        unset($this->serverless_parameters[$index]);
        $this->serverless_parameters = array_values($this->serverless_parameters);
    }

    /**
     * Save how the function is exposed over HTTP, and push it to the live
     * action when the host supports a metadata-only update.
     */
    public function saveServerlessHttpConfig(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->usesFunctionsRuntime()) {
            $this->toastError(__('HTTP settings apply to serverless functions only.'));

            return;
        }

        $this->validate([
            'serverless_web_mode' => ['required', 'string', Rule::in(FunctionConfiguration::MODES)],
            'serverless_cors_origins' => ['nullable', 'string', 'max:2000'],
            'serverless_cors_methods' => ['array'],
            'serverless_cors_methods.*' => ['string', Rule::in(FunctionCorsPolicy::METHODS)],
            'serverless_cors_headers' => ['nullable', 'string', 'max:2000'],
            'serverless_cors_max_age' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'serverless_log_provider' => ['nullable', 'string', Rule::in(array_merge([''], FunctionLogForwarding::PROVIDERS))],
            // required_with treats an empty provider as absent, so the token
            // is only demanded once a destination has actually been chosen.
            'serverless_log_token' => ['nullable', 'string', 'max:512', 'required_with:serverless_log_provider'],
            'serverless_log_endpoint' => ['nullable', 'string', 'max:512'],
            'serverless_parameters' => ['array', 'max:100'],
            'serverless_parameters.*.key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/'],
            'serverless_parameters.*.value' => ['nullable', 'string', 'max:8000'],
        ], [
            'serverless_parameters.*.key.regex' => __('Parameter names must start with a letter or underscore and contain only letters, digits, underscores, dots, and hyphens.'),
            'serverless_log_token.required_with' => __('Enter the token for the log destination.'),
        ], [
            'serverless_web_mode' => __('HTTP access'),
            'serverless_cors_origins' => __('allowed origins'),
            'serverless_cors_max_age' => __('preflight cache'),
        ]);

        $result = $this->runtimeSettings()->saveHttpConfig($this->site, [
            'web_mode' => $this->serverless_web_mode,
            'secured' => $this->serverless_secured,
            'provide_api_key' => $this->serverless_provide_api_key,
            'cors' => [
                'enabled' => $this->serverless_cors_enabled,
                'allow_origins' => $this->serverless_cors_origins,
                'allow_methods' => $this->serverless_cors_methods,
                'allow_headers' => $this->serverless_cors_headers,
                'allow_credentials' => $this->serverless_cors_credentials,
                'max_age' => trim($this->serverless_cors_max_age) === '' ? null : (int) $this->serverless_cors_max_age,
            ],
            'parameters' => $this->serverless_parameters,
            'parameters_final' => $this->serverless_parameters_final,
            'log_forwarding' => [
                'provider' => $this->serverless_log_provider,
                'token' => $this->serverless_log_token,
                'endpoint' => $this->serverless_log_endpoint,
            ],
        ]);

        $this->syncServerlessRuntimeFromSite();

        if (! $result['ok']) {
            $this->toastError((string) ($result['error'] ?? __('The host rejected the configuration.')));

            return;
        }

        $this->toastSuccess($result['applied']
            ? __('HTTP settings saved and applied to the live function.')
            : __('HTTP settings saved — they apply on the next deploy.'));
    }

    /**
     * Replace the endpoint secret. Every caller using the old value starts
     * getting 401s, so this is deliberately its own button rather than a
     * side effect of saving.
     */
    public function rotateServerlessEndpointSecret(): void
    {
        $this->authorize('update', $this->site);

        $result = $this->runtimeSettings()->rotateEndpointSecret($this->site);

        if (! $result['ok']) {
            $this->toastError((string) ($result['error'] ?? __('The host rejected the new secret.')));

            return;
        }

        $this->toastSuccess($result['applied']
            ? __('Endpoint secret rotated — callers must use the new value now.')
            : __('Endpoint secret rotated — it applies on the next deploy.'));
    }

    public function saveServerlessRuntime(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->usesFunctionsRuntime()) {
            $this->toastError(__('Resource limits apply to serverless functions only.'));

            return;
        }

        $this->validate([
            'serverless_memory' => ['required', 'integer', Rule::in(Site::SERVERLESS_MEMORY_OPTIONS_MB)],
            'serverless_timeout_ms' => ['required', 'integer', 'min:'.Site::SERVERLESS_MIN_TIMEOUT_MS, 'max:'.Site::SERVERLESS_MAX_TIMEOUT_MS],
            'serverless_concurrency' => ['required', 'integer', 'min:1', 'max:'.Site::SERVERLESS_MAX_CONCURRENCY],
            'serverless_logs_kb' => ['required', 'integer', 'min:'.Site::SERVERLESS_MIN_LOGS_KB, 'max:'.Site::SERVERLESS_MAX_LOGS_KB],
        ], [], [
            'serverless_memory' => __('memory'),
            'serverless_timeout_ms' => __('timeout'),
            'serverless_concurrency' => __('concurrency'),
            'serverless_logs_kb' => __('log capture'),
        ]);

        $this->runtimeSettings()->saveLimits($this->site, [
            'memory_mb' => $this->serverless_memory,
            'timeout_ms' => $this->serverless_timeout_ms,
            'concurrency' => $this->serverless_concurrency,
            'logs_kb' => $this->serverless_logs_kb,
        ]);

        $this->toastSuccess(__('Resource limits saved — they apply on the next deploy.'));
    }

    public function saveServerlessMaintenance(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->usesFunctionsRuntime() || ! $this->site->isLaravelFrameworkDetected()) {
            $this->toastError(__('Maintenance applies to Laravel functions only.'));

            return;
        }

        $result = $this->runtimeSettings()->setMaintenance($this->site, $this->serverless_maintenance);

        $this->syncServerlessRuntimeFromSite();

        if (! $result['ok']) {
            $this->toastError((string) ($result['error'] ?? __('The host rejected the maintenance update.')));

            return;
        }

        $this->toastSuccess($result['applied']
            ? ($this->serverless_maintenance
                ? __('Maintenance is on — visitors see a 503 until you turn it off.')
                : __('Maintenance is off — the function is serving traffic again.'))
            : __('Maintenance saved — it applies on the next deploy.'));
    }

    /**
     * Persist Warm start. Takes effect on the next control-plane tick —
     * no redeploy. Background processing already holds the function warm,
     * so enabling this then does not send a second ping.
     */
    public function toggleServerlessKeepWarm(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->site->usesFunctionsRuntime()) {
            $this->toastError(__('Warm start applies to serverless functions only.'));

            return;
        }

        $settings = $this->runtimeSettings();
        $enabled = $settings->setKeepWarm($this->site, ! $this->site->serverlessKeepWarmEnabled());
        $this->site->setAttribute('meta', $this->site->meta);

        if ($enabled && $this->site->serverlessBackgroundProcessingEnabled()) {
            $this->toastSuccess(__('Warm start saved. Background processing already holds the function warm, so dply will not send a second ping.'));

            return;
        }

        $this->toastSuccess($enabled
            ? __('Warm start on — dply pings the function every minute so visitors skip the cold start. No redeploy needed.')
            : __('Warm start off — the first request after idle pays the full boot cost.'));
    }
}
