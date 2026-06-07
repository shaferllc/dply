<?php

namespace App\Services\Logging;

/**
 * Helpers for the logging *spec* — the secret-free structure stored on
 * `SiteBinding::$config` and consumed by {@see LoggingConfigGenerator}.
 *
 * Two jobs:
 *  - {@see self::fromLegacyProvider()} converts the original single-provider
 *    drain shape (`config: {provider}`) into a v2 spec, behaviour-preservingly
 *    (Q8). The migration and the manager both lean on it so the old UI keeps
 *    working until the Phase 3 editor lands.
 *  - {@see self::defaultEnvKey()} assigns the `env()` key a secret field is
 *    referenced by, preferring the historical key names for the canonical
 *    drain channels (so a migrated binding's already-pushed `injected_env`
 *    keeps matching the generated file).
 */
final class LoggingSpec
{
    public const VERSION = 2;

    /**
     * Historical env-key names per drain type, kept so migrated bindings don't
     * need their `injected_env` rewritten (their secrets already live under
     * these keys).
     *
     * @var array<string, array<string, string>>
     */
    private const LEGACY_ENV = [
        LoggingChannelCatalog::PAPERTRAIL => ['host' => 'PAPERTRAIL_URL', 'port' => 'PAPERTRAIL_PORT'],
        // dply Realtime gets DEDICATED keys, not PAPERTRAIL_* — otherwise a site
        // with both a real Papertrail channel and dply Realtime would collide on
        // the same env keys and one would clobber the other.
        LoggingChannelCatalog::DPLY_REALTIME => ['host' => 'DPLY_LOG_DRAIN_HOST', 'port' => 'DPLY_LOG_DRAIN_PORT', 'token' => 'DPLY_LOG_DRAIN_TOKEN'],
        LoggingChannelCatalog::LOGTAIL => ['source_token' => 'LOGTAIL_SOURCE_TOKEN'],
        LoggingChannelCatalog::SLACK => ['webhook_url' => 'LOG_SLACK_WEBHOOK_URL'],
    ];

    /**
     * The env() key a secret field is referenced by. Canonical drain channels
     * (named after their type) reuse the historical key; everything else gets a
     * collision-proof `LOG_<NAME>_<FIELD>` so two channels of the same type can
     * coexist.
     */
    public static function defaultEnvKey(string $type, string $channelName, string $field): string
    {
        if ($channelName === $type && isset(self::LEGACY_ENV[$type][$field])) {
            return self::LEGACY_ENV[$type][$field];
        }

        return 'LOG_'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $channelName) ?? '').'_'.strtoupper($field);
    }

    /**
     * Build the behaviour-preserving v2 spec for an original single-provider
     * drain. Mirrors the exact env the old `logDrainEnv()` produced:
     *  - papertrail   → default=papertrail (no stack)
     *  - logtail      → default=stack of [single, logtail]
     *  - syslog       → default=syslog
     *  - dply_realtime→ default=dply_realtime (endpoint from config, env keys legacy)
     *
     * @param  array<string, string>  $creds
     * @return array<string, mixed>
     */
    public static function fromLegacyProvider(string $provider, array $creds): array
    {
        $base = static fn (array $channels, string $default, array $stack = []): array => [
            'version' => self::VERSION,
            'default' => $default,
            'stack' => $stack,
            'deprecations' => ['channel' => 'null', 'trace' => false],
            'channels' => $channels,
        ];

        return match ($provider) {
            LoggingChannelCatalog::PAPERTRAIL => $base([
                self::channel('papertrail', LoggingChannelCatalog::PAPERTRAIL),
            ], 'papertrail'),

            LoggingChannelCatalog::LOGTAIL => $base([
                self::channel('single', LoggingChannelCatalog::FILE_SINGLE),
                self::channel('logtail', LoggingChannelCatalog::LOGTAIL),
            ], 'stack', ['single', 'logtail']),

            LoggingChannelCatalog::SYSLOG => $base([
                self::channel('syslog', LoggingChannelCatalog::SYSLOG),
            ], 'syslog'),

            LoggingChannelCatalog::DPLY_REALTIME => $base([
                self::channel('dply_realtime', LoggingChannelCatalog::DPLY_REALTIME),
            ], 'dply_realtime'),

            default => $base([self::channel('single', LoggingChannelCatalog::FILE_SINGLE)], 'single'),
        };
    }

    /**
     * One normalised channel entry with its secret-field env map resolved.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function channel(string $name, string $type, array $extra = []): array
    {
        $channel = array_merge([
            'name' => $name,
            'type' => $type,
            'level' => 'debug',
        ], $extra);

        $env = [];
        foreach (LoggingChannelCatalog::secretFields($type) as $field) {
            $env[$field] = self::defaultEnvKey($type, $name, $field);
        }
        if ($env !== []) {
            $channel['env'] = $env;
        }

        return $channel;
    }

    /**
     * Whether a stored config blob is already a v2 spec (vs the original
     * `{provider}` shape).
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function isV2(?array $config): bool
    {
        return is_array($config) && (int) ($config['version'] ?? 0) >= self::VERSION;
    }
}
