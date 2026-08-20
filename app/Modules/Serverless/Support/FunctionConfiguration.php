<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Modules\Serverless\Contracts\ServerlessFeature;

/**
 * Everything about a function that is configuration rather than code — how it
 * is exposed over HTTP, what it answers preflight with, and the parameters
 * bound to it at deploy time.
 *
 * This is the provider-neutral form. A backend translates it into its own
 * vocabulary: the DigitalOcean/OpenWhisk path turns {@see annotations()} into
 * the action's annotation list and {@see parameterPairs()} into its bound
 * default parameters. A backend that lacks a feature simply never asks for
 * that slice — {@see featuresRequired()} names what a given configuration
 * needs so the caller can check support before deploying.
 *
 * Persisted under `Site.meta.serverless.web` / `.parameters`; the Runtime tab
 * edits it and the deployer reads it.
 */
final class FunctionConfiguration
{
    /** Not reachable over HTTP — invocable only through the authenticated API. */
    public const MODE_OFF = 'off';

    /** A normal web function: the platform parses the request into parameters. */
    public const MODE_WEB = 'web';

    /** A raw web function: the handler receives the unparsed body and query. */
    public const MODE_RAW = 'raw';

    public const MODES = [self::MODE_OFF, self::MODE_WEB, self::MODE_RAW];

    /** Bound parameter the Laravel adapter reads for durable maintenance. */
    public const MAINTENANCE_PARAMETER_KEY = '__dply_maintenance';

    /**
     * @param  array<string, mixed>  $parameters  Default parameters bound to the function.
     */
    public function __construct(
        public readonly string $webMode = self::MODE_WEB,
        public readonly FunctionCorsPolicy $cors = new FunctionCorsPolicy,
        public readonly ?string $authSecret = null,
        public readonly bool $provideApiKey = false,
        public readonly bool $finalParameters = false,
        public readonly array $parameters = [],
        public readonly FunctionLogForwarding $logForwarding = new FunctionLogForwarding,
        public readonly bool $maintenance = false,
    ) {}

    /**
     * Build from a Site's `meta.serverless` block.
     *
     * Defaults matter here: a function saved before this configuration
     * existed has no `web` key at all, and it is already deployed as a plain
     * web action. So the absent case must resolve to MODE_WEB — anything
     * else would silently take a live function off the internet on its next
     * deploy.
     *
     * @param  array<string, mixed>  $serverlessConfig
     */
    public static function fromSiteConfig(array $serverlessConfig): self
    {
        $web = $serverlessConfig['web'] ?? [];
        $web = is_array($web) ? $web : [];

        $mode = (string) ($web['mode'] ?? self::MODE_WEB);
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_WEB;
        }

        $cors = FunctionCorsPolicy::fromArray(is_array($web['cors'] ?? null) ? $web['cors'] : []);

        $secret = trim((string) ($web['auth_secret'] ?? ''));
        $secured = (bool) ($web['secured'] ?? false);

        $parameters = $serverlessConfig['parameters'] ?? [];
        $parameters = is_array($parameters) ? $parameters : [];

        $maintenance = $serverlessConfig['maintenance'] ?? false;
        $maintenanceEnabled = is_array($maintenance)
            ? (bool) ($maintenance['enabled'] ?? false)
            : (bool) $maintenance;

        return new self(
            webMode: $mode,
            cors: $cors,
            // A secured endpoint with no minted secret would deploy an
            // annotation the caller can never satisfy — treat it as unsecured
            // until the secret exists (the Runtime tab mints it on save).
            authSecret: ($secured && $secret !== '') ? $secret : null,
            provideApiKey: (bool) ($web['provide_api_key'] ?? false),
            finalParameters: (bool) ($serverlessConfig['parameters_final'] ?? false),
            parameters: self::normaliseParameters($parameters),
            logForwarding: FunctionLogForwarding::fromArray(
                is_array($serverlessConfig['log_forwarding'] ?? null) ? $serverlessConfig['log_forwarding'] : []
            ),
            maintenance: $maintenanceEnabled,
        );
    }

    public function isWebEnabled(): bool
    {
        return $this->webMode !== self::MODE_OFF;
    }

    public function isSecured(): bool
    {
        return $this->isWebEnabled() && $this->authSecret !== null;
    }

    /**
     * The OpenWhisk annotation list this configuration deploys as.
     *
     * @return list<array{key: string, value: mixed}>
     */
    public function annotations(): array
    {
        $annotations = [
            // Without web-export the action exists but is not reachable over
            // HTTP — the invocation URL would 404.
            ['key' => 'web-export', 'value' => $this->isWebEnabled()],
        ];

        if ($this->webMode === self::MODE_RAW) {
            $annotations[] = ['key' => 'raw-http', 'value' => true];
        }

        if ($this->isWebEnabled() && $this->cors->enabled) {
            $annotations[] = ['key' => 'web-custom-options', 'value' => true];
        }

        if ($this->isSecured()) {
            $annotations[] = ['key' => 'require-whisk-auth', 'value' => $this->authSecret];
        }

        if ($this->provideApiKey) {
            $annotations[] = ['key' => 'provide-api-key', 'value' => true];
        }

        if ($this->finalParameters && $this->parameterPairs() !== []) {
            $annotations[] = ['key' => 'final', 'value' => true];
        }

        return $annotations;
    }

    /**
     * The bound default parameters, in OpenWhisk's `{key, value}` shape.
     *
     * The CORS policy rides along as one reserved parameter so the runtime
     * shims can answer preflight without a second source of truth.
     *
     * @return list<array{key: string, value: mixed}>
     */
    public function parameterPairs(): array
    {
        $pairs = [];

        foreach ($this->parameters as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => $value];
        }

        if ($this->isWebEnabled() && $this->cors->enabled) {
            $pairs[] = ['key' => FunctionCorsPolicy::PARAMETER_KEY, 'value' => $this->cors->toParameter()];
        }

        // Always bound so toggling maintenance off clears a previously-true
        // parameter on a live metadata update (the list is replaced wholesale).
        $pairs[] = ['key' => self::MAINTENANCE_PARAMETER_KEY, 'value' => $this->maintenance];

        // Log forwarding is configured the same way — the platform reads it
        // from an environment variable on the function, not from an API.
        $destinations = $this->logForwarding->toParameterValue();
        if ($destinations !== null) {
            $pairs[] = ['key' => FunctionLogForwarding::PARAMETER_KEY, 'value' => $destinations];
        }

        return $pairs;
    }

    /**
     * The capabilities a backend must have to deploy this configuration
     * faithfully. Anything not required is not checked — a plain web
     * function needs nothing beyond WebFunction.
     *
     * @return list<ServerlessFeature>
     */
    public function featuresRequired(): array
    {
        $required = [];

        if ($this->isWebEnabled()) {
            $required[] = ServerlessFeature::WebFunction;
        }

        if ($this->webMode === self::MODE_RAW) {
            $required[] = ServerlessFeature::RawHttp;
        }

        if ($this->isWebEnabled() && $this->cors->enabled) {
            $required[] = ServerlessFeature::CustomCors;
        }

        if ($this->isSecured()) {
            $required[] = ServerlessFeature::SecuredWeb;
        }

        if ($this->provideApiKey) {
            $required[] = ServerlessFeature::ApiKeyPassthrough;
        }

        if ($this->parameters !== []) {
            $required[] = ServerlessFeature::DefaultParameters;
        }

        if ($this->finalParameters && $this->parameters !== []) {
            $required[] = ServerlessFeature::FinalParameters;
        }

        if ($this->logForwarding->enabled()) {
            $required[] = ServerlessFeature::LogForwarding;
        }

        return $required;
    }

    /**
     * The persisted shape — the inverse of {@see fromSiteConfig()}, minus the
     * parameters (which live beside the `web` block, not inside it).
     *
     * @return array<string, mixed>
     */
    public function toWebArray(): array
    {
        return [
            'mode' => $this->webMode,
            'secured' => $this->authSecret !== null,
            'auth_secret' => $this->authSecret,
            'provide_api_key' => $this->provideApiKey,
            'cors' => $this->cors->toArray(),
        ];
    }

    /**
     * Drop anything that cannot be a parameter key, and reject dply's own
     * reserved keys so operator input can never shadow the CORS policy.
     *
     * @param  array<mixed, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function normaliseParameters(array $parameters): array
    {
        $out = [];

        foreach ($parameters as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || str_starts_with($key, '__dply_') || str_starts_with($key, '__ow_')) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
