<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

/**
 * Where a function's console and error logs are forwarded.
 *
 * DigitalOcean does not expose this as a namespace setting or an API call —
 * it is read from a `LOG_DESTINATIONS` environment variable on the function,
 * whose value is a JSON list of destination objects. So forwarding is
 * configured exactly like any other bound parameter, and
 * {@see FunctionConfiguration} emits it as one.
 *
 * Three providers are supported by the platform; a fourth cannot be added
 * here, because the runtime is what reads this variable.
 *
 * @see https://docs.digitalocean.com/products/functions/how-to/forward-logs/
 */
final class FunctionLogForwarding
{
    /** The environment variable the Functions runtime reads. */
    public const PARAMETER_KEY = 'LOG_DESTINATIONS';

    public const PROVIDER_NONE = '';

    public const PROVIDER_PAPERTRAIL = 'papertrail';

    public const PROVIDER_DATADOG = 'datadog';

    /** Better Stack, still named `logtail` in the destination payload. */
    public const PROVIDER_LOGTAIL = 'logtail';

    public const PROVIDERS = [
        self::PROVIDER_PAPERTRAIL,
        self::PROVIDER_DATADOG,
        self::PROVIDER_LOGTAIL,
    ];

    /** Datadog's default intake; other regions have their own host. */
    public const DATADOG_DEFAULT_ENDPOINT = 'https://http-intake.logs.datadoghq.com';

    public function __construct(
        public readonly string $provider = self::PROVIDER_NONE,
        public readonly string $token = '',
        public readonly string $endpoint = '',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $provider = trim((string) ($config['provider'] ?? ''));
        if (! in_array($provider, self::PROVIDERS, true)) {
            return new self;
        }

        $token = trim((string) ($config['token'] ?? ''));

        // A destination with no credential forwards nothing and would deploy
        // an env var the runtime rejects — treat it as not configured.
        if ($token === '') {
            return new self;
        }

        return new self(
            provider: $provider,
            token: $token,
            endpoint: trim((string) ($config['endpoint'] ?? '')),
        );
    }

    public function enabled(): bool
    {
        return $this->provider !== self::PROVIDER_NONE && $this->token !== '';
    }

    public function label(): string
    {
        return match ($this->provider) {
            self::PROVIDER_PAPERTRAIL => __('Papertrail'),
            self::PROVIDER_DATADOG => __('Datadog'),
            self::PROVIDER_LOGTAIL => __('Better Stack'),
            default => __('Off'),
        };
    }

    /**
     * The `LOG_DESTINATIONS` value: a JSON *string*, not a structure — the
     * runtime reads it as an environment variable.
     */
    public function toParameterValue(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $destination = match ($this->provider) {
            self::PROVIDER_DATADOG => ['datadog' => [
                'endpoint' => $this->endpoint !== '' ? $this->endpoint : self::DATADOG_DEFAULT_ENDPOINT,
                'api_key' => $this->token,
            ]],
            self::PROVIDER_PAPERTRAIL => ['papertrail' => ['token' => $this->token]],
            self::PROVIDER_LOGTAIL => ['logtail' => ['token' => $this->token]],
            default => null,
        };

        if ($destination === null) {
            return null;
        }

        return (string) json_encode([$destination], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'token' => $this->token,
            'endpoint' => $this->endpoint,
        ];
    }
}
