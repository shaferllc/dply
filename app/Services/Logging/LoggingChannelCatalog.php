<?php

namespace App\Services\Logging;

/**
 * The curated catalog of log channel *types* dply can generate (Q7 of the
 * managed-logging design). Each type maps to a known-good Laravel/Monolog
 * channel shape so the operator can never hand dply a driver/class it doesn't
 * understand — the one exception is {@see self::CUSTOM_MONOLOG}, the escape
 * hatch where the operator supplies handler/formatter/processor class names
 * dply cannot validate (flagged ⚠️ in the UI and gated by the deploy probe).
 *
 * This catalog is the single source of truth shared by the generator
 * ({@see LoggingConfigGenerator}), the spec validator, and the Livewire editor.
 * It is data, not behaviour: it describes fields and how a type emits, but the
 * actual PHP rendering lives in the generator.
 *
 * Secrets never appear here as values. A field marked `secret: true` means its
 * value is injected via the encrypted `injected_env` and the generated file
 * references it through `env(<key>)` — the Q4 env-ref split.
 */
final class LoggingChannelCatalog
{
    // Curated types.
    public const FILE_SINGLE = 'file_single';

    public const FILE_DAILY = 'file_daily';

    public const STDERR = 'stderr';

    public const SLACK = 'slack';

    public const SYSLOG = 'syslog';

    public const ERRORLOG = 'errorlog';

    public const PAPERTRAIL = 'papertrail';

    public const LOGTAIL = 'logtail';

    public const DPLY_REALTIME = 'dply_realtime';

    public const CUSTOM_MONOLOG = 'custom_monolog';

    /**
     * Monolog levels Laravel/PSR-3 accepts, low → high. Used to validate the
     * per-channel `level` and to populate the editor's level <select>.
     */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * The drain types whose secret lives in a reusable {@see \App\Models\LogDrainCredential}
     * and whose Composer transport the *app* must already require (dply runs the
     * app's own `composer install`, so it can't add it — same wall the mail
     * binding hit). Keyed slug → package, surfaced as a note in the editor.
     *
     * @var array<string, string>
     */
    public const TRANSPORT_PACKAGES = [
        self::LOGTAIL => 'better-stack/laravel-logs',
    ];

    /**
     * Static metadata per type. Shape:
     *   label           human label for the editor
     *   supports_level  whether the type exposes a per-channel level
     *   supports_format whether the type exposes the Line/JSON toggle (Q7)
     *   is_drain        ships log records off-box to a third party
     *   is_escape_hatch the ⚠️ unvalidated custom type
     *   fields          ordered list of operator-editable fields; each:
     *                     key, label, kind (text|number|select|toggle|secret|keyval|classlist),
     *                     secret (bool, value → injected_env), options (for select),
     *                     default, required, help
     *
     * @return array<string, array<string, mixed>>
     */
    public static function types(): array
    {
        return [
            self::FILE_SINGLE => [
                'label' => 'File (single)',
                'supports_level' => true,
                'supports_format' => true,
                'is_drain' => false,
                'is_escape_hatch' => false,
                'fields' => [],
            ],
            self::FILE_DAILY => [
                'label' => 'File (rotating)',
                'supports_level' => true,
                'supports_format' => true,
                'is_drain' => false,
                'is_escape_hatch' => false,
                'fields' => [
                    ['key' => 'days', 'label' => 'Retention (days)', 'kind' => 'number', 'default' => 14, 'required' => true],
                ],
            ],
            self::STDERR => [
                'label' => 'Stderr',
                'supports_level' => true,
                'supports_format' => true,
                'is_drain' => false,
                'is_escape_hatch' => false,
                'fields' => [],
            ],
            self::SLACK => [
                'label' => 'Slack',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => true,
                'is_escape_hatch' => false,
                'fields' => [
                    ['key' => 'webhook_url', 'label' => 'Incoming webhook URL', 'kind' => 'secret', 'secret' => true, 'required' => true],
                    ['key' => 'username', 'label' => 'Username', 'kind' => 'text', 'default' => 'Laravel Log'],
                    ['key' => 'emoji', 'label' => 'Emoji', 'kind' => 'text', 'default' => ':boom:'],
                ],
            ],
            self::SYSLOG => [
                'label' => 'Syslog',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => false,
                'is_escape_hatch' => false,
                'fields' => [
                    ['key' => 'facility', 'label' => 'Facility', 'kind' => 'text', 'default' => 'LOG_USER'],
                ],
            ],
            self::ERRORLOG => [
                'label' => 'Errorlog (SAPI)',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => false,
                'is_escape_hatch' => false,
                'fields' => [],
            ],
            self::PAPERTRAIL => [
                'label' => 'Papertrail',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => true,
                'is_escape_hatch' => false,
                'fields' => [
                    ['key' => 'host', 'label' => 'Host', 'kind' => 'secret', 'secret' => true, 'required' => true, 'default' => 'logs.papertrailapp.com'],
                    ['key' => 'port', 'label' => 'Port', 'kind' => 'secret', 'secret' => true, 'required' => true],
                ],
            ],
            self::LOGTAIL => [
                'label' => 'Logtail (Better Stack)',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => true,
                'is_escape_hatch' => false,
                'fields' => [
                    ['key' => 'source_token', 'label' => 'Source token', 'kind' => 'secret', 'secret' => true, 'required' => true],
                ],
            ],
            self::DPLY_REALTIME => [
                'label' => 'dply Realtime',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => true,
                'is_escape_hatch' => false,
                // host/port come from config('log_drains.dply_realtime.*'), not
                // operator input — so they're marked `system`: the editor hides
                // them, but they're still `secret` so the env map is populated
                // and the generated channel can reference env(host)/env(port).
                'fields' => [
                    ['key' => 'host', 'label' => 'Endpoint host', 'kind' => 'secret', 'secret' => true, 'system' => true],
                    ['key' => 'port', 'label' => 'Endpoint port', 'kind' => 'secret', 'secret' => true, 'system' => true],
                    // Per-site routing id stamped as the syslog ident so the
                    // receiver can map a datagram back to this site.
                    ['key' => 'token', 'label' => 'Routing token', 'kind' => 'secret', 'secret' => true, 'system' => true],
                ],
            ],
            self::CUSTOM_MONOLOG => [
                'label' => 'Custom (monolog) ⚠️',
                'supports_level' => true,
                'supports_format' => false,
                'is_drain' => false,
                'is_escape_hatch' => true,
                'fields' => [
                    ['key' => 'handler', 'label' => 'Handler class', 'kind' => 'text', 'required' => true, 'help' => 'Fully-qualified Monolog handler class. dply cannot verify it exists in your vendor/ — a typo fails the deploy probe, not production.'],
                    ['key' => 'handler_with', 'label' => 'Handler constructor args', 'kind' => 'keyval'],
                    ['key' => 'formatter', 'label' => 'Formatter class', 'kind' => 'text'],
                    ['key' => 'formatter_with', 'label' => 'Formatter args', 'kind' => 'keyval'],
                    ['key' => 'processors', 'label' => 'Processor classes', 'kind' => 'classlist'],
                ],
            ],
        ];
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::types());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    public static function isDrain(string $type): bool
    {
        return (bool) (self::get($type)['is_drain'] ?? false);
    }

    public static function isEscapeHatch(string $type): bool
    {
        return (bool) (self::get($type)['is_escape_hatch'] ?? false);
    }

    public static function supportsFormat(string $type): bool
    {
        return (bool) (self::get($type)['supports_format'] ?? false);
    }

    /**
     * The secret field keys for a type — the ones whose values go to
     * injected_env and are referenced via env() in the generated file.
     *
     * @return list<string>
     */
    public static function secretFields(string $type): array
    {
        $fields = self::get($type)['fields'] ?? [];

        return array_values(array_map(
            static fn (array $f): string => (string) $f['key'],
            array_filter($fields, static fn (array $f): bool => (bool) ($f['secret'] ?? false)),
        ));
    }
}
