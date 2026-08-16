<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

/**
 * A function's CORS policy, normalised from operator input.
 *
 * DigitalOcean Functions applies permissive defaults (`*` origin, a fixed
 * method list) unless the action carries the `web-custom-options` annotation,
 * at which point the *function* owns every CORS response header and the
 * platform stops answering OPTIONS on its behalf. So a policy here travels
 * two ways at deploy time: the annotation flips the platform off, and the
 * policy itself is bound as a default parameter the dply runtime shims read
 * to emit the headers and answer preflight.
 *
 * @see https://docs.digitalocean.com/products/functions/how-to/set-custom-cors-headers/
 */
final class FunctionCorsPolicy
{
    /** The parameter dply binds the policy to; the shims read this key. */
    public const PARAMETER_KEY = '__dply_cors';

    /** Methods offered in the UI — the superset a web function can answer. */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * @param  list<string>  $allowOrigins
     * @param  list<string>  $allowMethods
     * @param  list<string>  $allowHeaders
     * @param  list<string>  $exposeHeaders
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $allowOrigins = ['*'],
        public readonly array $allowMethods = ['GET', 'POST', 'OPTIONS'],
        public readonly array $allowHeaders = ['Content-Type', 'Authorization'],
        public readonly array $exposeHeaders = [],
        public readonly bool $allowCredentials = false,
        public readonly ?int $maxAge = null,
    ) {}

    /**
     * Build from the persisted `meta.serverless.web.cors` block, dropping
     * anything malformed rather than deploying a half-formed policy.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $origins = self::stringList($config['allow_origins'] ?? $config['allow_origin'] ?? ['*']);
        $methods = self::stringList($config['allow_methods'] ?? []);
        $methods = array_values(array_filter(
            array_map(static fn (string $m): string => strtoupper($m), $methods),
            static fn (string $m): bool => in_array($m, self::METHODS, true),
        ));

        $maxAge = $config['max_age'] ?? null;
        $maxAge = is_numeric($maxAge) ? max(0, (int) $maxAge) : null;

        $credentials = (bool) ($config['allow_credentials'] ?? false);

        // `Access-Control-Allow-Credentials: true` with a wildcard origin is
        // rejected by every browser — the pair is meaningless, so drop the
        // credentials flag rather than shipping a policy that cannot work.
        if ($credentials && in_array('*', $origins, true)) {
            $credentials = false;
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            allowOrigins: $origins === [] ? ['*'] : $origins,
            allowMethods: $methods === [] ? ['GET', 'POST', 'OPTIONS'] : $methods,
            allowHeaders: self::stringList($config['allow_headers'] ?? ['Content-Type', 'Authorization']),
            exposeHeaders: self::stringList($config['expose_headers'] ?? []),
            allowCredentials: $credentials,
            maxAge: $maxAge,
        );
    }

    /**
     * The wire shape bound as a default parameter and read by the shims.
     *
     * OPTIONS is always answerable once a custom policy is in force — the
     * platform has stopped doing it, so a preflight the shim refuses would
     * be a policy that blocks the very requests it exists to allow.
     *
     * @return array<string, mixed>
     */
    public function toParameter(): array
    {
        $methods = $this->allowMethods;
        if (! in_array('OPTIONS', $methods, true)) {
            $methods[] = 'OPTIONS';
        }

        return array_filter([
            'allow_origins' => $this->allowOrigins,
            'allow_methods' => $methods,
            'allow_headers' => $this->allowHeaders,
            'expose_headers' => $this->exposeHeaders,
            'allow_credentials' => $this->allowCredentials,
            'max_age' => $this->maxAge,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * The persisted shape, for round-tripping through `meta.serverless`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'allow_origins' => $this->allowOrigins,
            'allow_methods' => $this->allowMethods,
            'allow_headers' => $this->allowHeaders,
            'expose_headers' => $this->exposeHeaders,
            'allow_credentials' => $this->allowCredentials,
            'max_age' => $this->maxAge,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $entry = trim($entry);
            if ($entry !== '' && ! in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
