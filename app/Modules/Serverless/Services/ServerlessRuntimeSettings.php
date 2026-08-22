<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Support\FunctionConfiguration;
use App\Modules\Serverless\Support\FunctionCorsPolicy;
use App\Modules\Serverless\Support\FunctionLogForwarding;
use Illuminate\Support\Str;

/**
 * The Runtime tab's settings, as data: resource limits, how the function is
 * exposed over HTTP (web mode, endpoint secret, CORS), its bound parameters,
 * log forwarding, maintenance, and warm start.
 *
 * The workspace page ({@see \App\Livewire\Concerns\ManagesServerlessRuntime})
 * and the public API both write through here. Two things make a shared owner
 * worth it: the meta shaping is fiddly (secret minting on first secure, the
 * Datadog-only endpoint rule, CORS normalisation), and every HTTP-config write
 * must be followed by {@see ServerlessFunctionConfigurator} so the live action
 * matches what dply stored.
 */
final class ServerlessRuntimeSettings
{
    public function __construct(
        private readonly ServerlessFunctionConfigurator $configurator,
        private readonly ServerlessMaintenance $maintenance,
    ) {}

    /**
     * Everything the Runtime tab shows, in one payload.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Site $site): array
    {
        $limits = Site::normalizeServerlessLimits($site->serverlessLimits());
        $configuration = FunctionConfiguration::fromSiteConfig($site->serverlessConfig());
        $cors = $configuration->cors;
        $forwarding = $configuration->logForwarding;

        return [
            'limits' => [
                'memory_mb' => $limits['memory'],
                'timeout_ms' => $limits['timeout'],
                'concurrency' => $limits['concurrency'],
                'logs_kb' => $limits['logs'],
            ],
            'http' => [
                'web_mode' => $configuration->webMode,
                'secured' => $configuration->isSecured(),
                'provide_api_key' => $configuration->provideApiKey,
                'cors' => [
                    'enabled' => $cors->enabled,
                    'allow_origins' => $cors->allowOrigins,
                    'allow_methods' => $cors->allowMethods,
                    'allow_headers' => $cors->allowHeaders,
                    'allow_credentials' => $cors->allowCredentials,
                    'max_age' => $cors->maxAge,
                ],
            ],
            // Values are the function's bound defaults, not secrets in the
            // env sense — the tab shows them in the clear, so the API may too.
            'parameters' => $configuration->parameters,
            'parameters_final' => $configuration->finalParameters,
            'log_forwarding' => [
                'provider' => $forwarding->provider,
                // The token is a credential: report only whether one is held.
                'token_set' => trim($forwarding->token) !== '',
                'endpoint' => $forwarding->endpoint,
            ],
            'maintenance' => $configuration->maintenance,
            'keep_warm' => $site->serverlessKeepWarmEnabled(),
        ];
    }

    /**
     * Resource limits. Persisted only — the deployer writes them onto the
     * action, so they land on the next deploy.
     *
     * @param  array{memory_mb: int, timeout_ms: int, concurrency: int, logs_kb: int}  $limits
     */
    public function saveLimits(Site $site, array $limits): void
    {
        $serverless = $this->config($site);
        $serverless['limits'] = [
            'memory' => $limits['memory_mb'],
            'timeout' => $limits['timeout_ms'],
            'concurrency' => $limits['concurrency'],
            'logs' => $limits['logs_kb'],
        ];

        $this->write($site, $serverless);
    }

    /**
     * How the function is exposed over HTTP, plus bound parameters and log
     * forwarding — action metadata, so it is pushed to the live action here
     * rather than waiting for a deploy.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, applied: bool, error: ?string}
     */
    public function saveHttpConfig(Site $site, array $input): array
    {
        $serverless = $this->config($site);
        $web = is_array($serverless['web'] ?? null) ? $serverless['web'] : [];
        $secured = (bool) $input['secured'];

        // Mint the endpoint secret the first time the operator secures the
        // function, and keep it across saves — rotating is a separate,
        // explicit action, because every existing caller breaks on rotation.
        $secret = trim((string) ($web['auth_secret'] ?? ''));
        if ($secured && $secret === '') {
            $secret = Str::random(48);
        }

        $cors = (array) ($input['cors'] ?? []);

        $serverless['web'] = [
            'mode' => (string) $input['web_mode'],
            'secured' => $secured,
            'auth_secret' => $secret !== '' ? $secret : null,
            'provide_api_key' => (bool) $input['provide_api_key'],
            'cors' => FunctionCorsPolicy::fromArray([
                'enabled' => (bool) ($cors['enabled'] ?? false),
                'allow_origins' => $cors['allow_origins'] ?? '*',
                'allow_methods' => $cors['allow_methods'] ?? FunctionCorsPolicy::METHODS,
                'allow_headers' => $cors['allow_headers'] ?? '',
                'allow_credentials' => (bool) ($cors['allow_credentials'] ?? false),
                'max_age' => $cors['max_age'] ?? null,
            ])->toArray(),
        ];

        $serverless['parameters'] = $this->cleanParameters((array) ($input['parameters'] ?? []));
        $serverless['parameters_final'] = (bool) ($input['parameters_final'] ?? false);

        // Datadog is the only destination with a host to point at, and it has
        // a default — so an empty endpoint means "use the default", not
        // "misconfigured".
        $provider = (string) ($input['log_forwarding']['provider'] ?? '');
        $endpoint = trim((string) ($input['log_forwarding']['endpoint'] ?? ''));

        $serverless['log_forwarding'] = FunctionLogForwarding::fromArray([
            'provider' => $provider,
            'token' => trim((string) ($input['log_forwarding']['token'] ?? '')),
            'endpoint' => $provider === FunctionLogForwarding::PROVIDER_DATADOG ? $endpoint : '',
        ])->toArray();

        $this->write($site, $serverless);

        return $this->apply($site);
    }

    /**
     * Replace the endpoint secret. Every caller using the old value starts
     * getting 401s, so this is deliberately its own action.
     *
     * @return array{ok: bool, applied: bool, error: ?string}
     */
    public function rotateEndpointSecret(Site $site): array
    {
        $serverless = $this->config($site);
        $web = is_array($serverless['web'] ?? null) ? $serverless['web'] : [];

        if (! ($web['secured'] ?? false)) {
            return ['ok' => false, 'applied' => false, 'error' => 'Secure the endpoint before rotating its secret.'];
        }

        $web['auth_secret'] = Str::random(48);
        $serverless['web'] = $web;

        $this->write($site, $serverless);

        return $this->apply($site);
    }

    /**
     * @return array{ok: bool, applied: bool, error: ?string}
     */
    public function setMaintenance(Site $site, bool $enabled): array
    {
        $result = $this->maintenance->setEnabled($site, $enabled);
        $site->refresh();

        return [
            'ok' => $result['ok'],
            'applied' => $result['applied'],
            'error' => $result['error'] === null ? null : (string) $result['error'],
        ];
    }

    /** Warm start takes effect on the next control-plane tick — no redeploy. */
    public function setKeepWarm(Site $site, bool $enabled): bool
    {
        return $site->setServerlessKeepWarm($enabled);
    }

    /**
     * Bound parameters: drop unnamed rows, stringify values.
     *
     * @param  array<array-key, mixed>  $rows  either {key, value} rows or a key => value map
     * @return array<string, string>
     */
    private function cleanParameters(array $rows): array
    {
        $parameters = [];

        foreach ($rows as $key => $row) {
            if (is_array($row)) {
                $key = trim((string) ($row['key'] ?? ''));
                $value = (string) ($row['value'] ?? '');
            } else {
                $key = trim((string) $key);
                $value = (string) $row;
            }

            if ($key === '') {
                continue;
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * @return array{ok: bool, applied: bool, error: ?string}
     */
    private function apply(Site $site): array
    {
        $result = $this->configurator->apply($site);

        return [
            'ok' => $result['ok'],
            'applied' => $result['applied'],
            'error' => $result['error'] === null ? null : (string) $result['error'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];

        return is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
    }

    /**
     * @param  array<string, mixed>  $serverless
     */
    private function write(Site $site, array $serverless): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
    }
}
