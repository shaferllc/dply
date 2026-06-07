<?php

namespace App\Services\Logging;

use InvalidArgumentException;

/**
 * Renders a site's logging spec into a complete `config/logging.php` PHP file
 * (Q3 "dply owns the file"). The output is **structure only and secret-free**:
 * every credential is referenced via `env(<KEY>)`, and the actual value rides
 * the encrypted `injected_env` → `.env` (the Q4 env-ref split). This file is
 * therefore safe to preview in the UI and diff in plain text.
 *
 * The spec shape (stored on `SiteBinding::$config`, an *unencrypted* array):
 *   [
 *     'version'      => 2,
 *     'default'      => 'stack',                 // a channel name (may be 'stack')
 *     'stack'        => ['single', 'papertrail'],// composes the 'stack' channel; [] = none
 *     'deprecations' => ['channel' => 'null', 'trace' => false],
 *     'channels'     => [
 *        ['name' => 'single', 'type' => 'file_single', 'level' => 'debug', 'format' => 'line'],
 *        ['name' => 'papertrail', 'type' => 'papertrail', 'level' => 'debug',
 *         'env' => ['host' => 'PAPERTRAIL_URL', 'port' => 'PAPERTRAIL_PORT']],
 *        ...
 *     ],
 *   ]
 *
 * Class names are emitted fully-qualified with a leading backslash so the
 * generated file needs no `use` imports and can't collide with anything.
 */
final class LoggingConfigGenerator
{
    private const HEADER = <<<'TXT'
<?php

/*
|--------------------------------------------------------------------------
| Managed by dply — do not edit.
|--------------------------------------------------------------------------
|
| This file is generated from the site's logging binding and overwritten on
| every deploy. Edit logging from the dply dashboard (Logs tab), not here.
| Secrets are never written here; they are referenced via env() and injected
| through the deploy secret store.
|
*/

TXT;

    /**
     * @param  array<string, mixed>  $spec
     */
    public function generate(array $spec): string
    {
        $channels = $this->channelDefs($spec);

        // `default` and `deprecations` are emitted as *literals*, not
        // env('LOG_CHANNEL'…): because dply owns this file, the spec is the
        // single authoritative source of structure. Baking literals makes the
        // generated file win unconditionally — a stale LOG_CHANNEL left in a
        // site's .env can't silently override the operator's chosen default.
        // Only secrets stay behind env() (the Q4 split).
        $root = [
            'default' => (string) ($spec['default'] ?? 'stack'),
            'deprecations' => [
                'channel' => (string) ($spec['deprecations']['channel'] ?? 'null'),
                'trace' => (bool) ($spec['deprecations']['trace'] ?? false),
            ],
            'channels' => $channels,
        ];

        return self::HEADER."\nreturn ".$this->export($root, 0).";\n";
    }

    /**
     * Build the `channels` map from the spec, always appending Laravel's
     * baseline `null` + `emergency` channels (the framework falls back to
     * `emergency`, and `deprecations` defaults to `null`).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function channelDefs(array $spec): array
    {
        $out = [];

        $stack = array_values(array_filter(array_map('strval', (array) ($spec['stack'] ?? []))));
        if ($stack !== []) {
            $out['stack'] = [
                'driver' => 'stack',
                'channels' => $stack,
                'ignore_exceptions' => false,
            ];
        }

        foreach ((array) ($spec['channels'] ?? []) as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $name = (string) ($channel['name'] ?? '');
            if ($name === '' || $name === 'stack') {
                continue; // 'stack' is reserved for the composer above
            }
            $out[$name] = $this->renderChannel($channel);
        }

        $out['null'] = ['driver' => 'monolog', 'handler' => new RawPhp('\\Monolog\\Handler\\NullHandler::class')];
        $out['emergency'] = ['path' => new RawPhp("storage_path('logs/laravel.log')")];

        return $out;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function renderChannel(array $c): array
    {
        $type = (string) ($c['type'] ?? '');
        $level = $this->level($c);
        $format = (string) ($c['format'] ?? 'line');
        $env = is_array($c['env'] ?? null) ? $c['env'] : [];

        return match ($type) {
            LoggingChannelCatalog::FILE_SINGLE => $format === 'json'
                ? $this->monolog('\\Monolog\\Handler\\StreamHandler', ['stream' => new RawPhp("storage_path('logs/laravel.log')")], $level, json: true)
                : ['driver' => 'single', 'path' => new RawPhp("storage_path('logs/laravel.log')"), 'level' => $level, 'replace_placeholders' => true],

            LoggingChannelCatalog::FILE_DAILY => $format === 'json'
                ? $this->monolog('\\Monolog\\Handler\\RotatingFileHandler', ['filename' => new RawPhp("storage_path('logs/laravel.log')"), 'maxFiles' => (int) ($c['days'] ?? 14)], $level, json: true)
                : ['driver' => 'daily', 'path' => new RawPhp("storage_path('logs/laravel.log')"), 'level' => $level, 'days' => (int) ($c['days'] ?? 14), 'replace_placeholders' => true],

            LoggingChannelCatalog::STDERR => $this->monolog('\\Monolog\\Handler\\StreamHandler', ['stream' => 'php://stderr'], $level, json: $format === 'json'),

            LoggingChannelCatalog::SLACK => [
                'driver' => 'slack',
                'url' => new RawPhp('env('.$this->str($this->envKey($env, 'webhook_url')).')'),
                'username' => (string) ($c['username'] ?? 'Laravel Log'),
                'emoji' => (string) ($c['emoji'] ?? ':boom:'),
                'level' => $level,
                'replace_placeholders' => true,
            ],

            LoggingChannelCatalog::SYSLOG => [
                'driver' => 'syslog',
                'level' => $level,
                'facility' => new RawPhp('\\'.$this->facility((string) ($c['facility'] ?? 'LOG_USER'))),
                'replace_placeholders' => true,
            ],

            LoggingChannelCatalog::ERRORLOG => ['driver' => 'errorlog', 'level' => $level, 'replace_placeholders' => true],

            LoggingChannelCatalog::PAPERTRAIL, LoggingChannelCatalog::DPLY_REALTIME => $this->syslogUdp(
                $this->envKey($env, 'host'),
                $this->envKey($env, 'port'),
                $level,
            ),

            LoggingChannelCatalog::LOGTAIL => $this->monolog(
                '\\Logtail\\Monolog\\LogtailHandler',
                ['sourceToken' => new RawPhp('env('.$this->str($this->envKey($env, 'source_token')).')')],
                $level,
            ),

            LoggingChannelCatalog::CUSTOM_MONOLOG => $this->custom($c, $level),

            default => throw new InvalidArgumentException("Unknown log channel type [{$type}]."),
        };
    }

    /**
     * A `monolog`-driver channel wrapping a single handler. Always carries the
     * PsrLogMessageProcessor so `{placeholder}` interpolation works (Q7), and
     * a JsonFormatter when the caller asked for JSON output.
     *
     * @param  array<string, mixed>  $handlerWith
     * @return array<string, mixed>
     */
    private function monolog(string $handlerClass, array $handlerWith, string $level, bool $json = false): array
    {
        $def = [
            'driver' => 'monolog',
            'level' => $level,
            'handler' => new RawPhp($handlerClass.'::class'),
            'handler_with' => $handlerWith,
        ];
        if ($json) {
            $def['formatter'] = new RawPhp('\\Monolog\\Formatter\\JsonFormatter::class');
        }
        $def['processors'] = [new RawPhp('\\Monolog\\Processor\\PsrLogMessageProcessor::class')];

        return $def;
    }

    /**
     * Papertrail / dply Realtime: a SyslogUdpHandler over TLS to host:port,
     * both supplied via env().
     *
     * @return array<string, mixed>
     */
    private function syslogUdp(string $hostKey, string $portKey, string $level): array
    {
        $host = 'env('.$this->str($hostKey).')';
        $port = 'env('.$this->str($portKey).')';

        return [
            'driver' => 'monolog',
            'level' => $level,
            'handler' => new RawPhp('\\Monolog\\Handler\\SyslogUdpHandler::class'),
            'handler_with' => [
                'host' => new RawPhp($host),
                'port' => new RawPhp($port),
                'connectionString' => new RawPhp("'tls://'.".$host.".':'.".$port),
            ],
            'processors' => [new RawPhp('\\Monolog\\Processor\\PsrLogMessageProcessor::class')],
        ];
    }

    /**
     * The ⚠️ escape hatch: operator-supplied handler/formatter/processor class
     * names. dply cannot verify these exist — the deploy resolution probe (Q9)
     * is what catches a bad class before the symlink flips.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function custom(array $c, string $level): array
    {
        $handler = $this->classRef((string) ($c['handler'] ?? ''));
        if ($handler === null) {
            throw new InvalidArgumentException('Custom channel requires a handler class.');
        }

        $def = [
            'driver' => 'monolog',
            'level' => $level,
            'handler' => new RawPhp($handler),
        ];

        $handlerWith = $this->stringMap($c['handler_with'] ?? []);
        if ($handlerWith !== []) {
            $def['handler_with'] = $handlerWith;
        }

        if (($formatter = $this->classRef((string) ($c['formatter'] ?? ''))) !== null) {
            $def['formatter'] = new RawPhp($formatter);
            $formatterWith = $this->stringMap($c['formatter_with'] ?? []);
            if ($formatterWith !== []) {
                $def['formatter_with'] = $formatterWith;
            }
        }

        $processors = [];
        foreach ((array) ($c['processors'] ?? []) as $p) {
            $ref = $this->classRef((string) $p);
            if ($ref !== null) {
                $processors[] = new RawPhp($ref);
            }
        }
        // Keep placeholder interpolation unless the operator brought their own.
        if ($processors === []) {
            $processors[] = new RawPhp('\\Monolog\\Processor\\PsrLogMessageProcessor::class');
        }
        $def['processors'] = $processors;

        return $def;
    }

    /** @param  array<string, mixed>  $c */
    private function level(array $c): string
    {
        $level = strtolower(trim((string) ($c['level'] ?? 'debug')));

        return in_array($level, LoggingChannelCatalog::LEVELS, true) ? $level : 'debug';
    }

    /** @param  array<string, mixed>  $env */
    private function envKey(array $env, string $field): string
    {
        $key = strtoupper(trim((string) ($env[$field] ?? '')));
        if ($key === '' || ! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Missing or invalid env key for secret field [{$field}].");
        }

        return $key;
    }

    /**
     * Validate a syslog facility against PHP's LOG_* constants so we never emit
     * an undefined constant into the generated file.
     */
    private function facility(string $name): string
    {
        $name = strtoupper(trim($name));
        $allowed = ['LOG_USER', 'LOG_LOCAL0', 'LOG_LOCAL1', 'LOG_LOCAL2', 'LOG_LOCAL3', 'LOG_LOCAL4', 'LOG_LOCAL5', 'LOG_LOCAL6', 'LOG_LOCAL7', 'LOG_DAEMON', 'LOG_SYSLOG', 'LOG_AUTH', 'LOG_MAIL'];
        if (! in_array($name, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported syslog facility [{$name}].");
        }

        return $name;
    }

    /**
     * Normalise an operator-supplied class name into a `\Fqn::class` expression,
     * or null when blank. Rejects anything that isn't a plausible PHP FQN so a
     * stray value can't break the file's *syntax* (existence is the probe's job).
     */
    private function classRef(string $fqcn): ?string
    {
        $fqcn = trim($fqcn);
        if ($fqcn === '') {
            return null;
        }
        $fqcn = ltrim($fqcn, '\\');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $fqcn)) {
            throw new InvalidArgumentException("Invalid class name [{$fqcn}].");
        }

        return '\\'.$fqcn.'::class';
    }

    /**
     * @param  mixed  $map
     * @return array<string, string>
     */
    private function stringMap($map): array
    {
        if (! is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && $k !== '') {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    // ---- PHP value exporter ----------------------------------------------

    /**
     * Recursively export a PHP value as source. Honours {@see RawPhp} (emitted
     * verbatim, e.g. `env('X')` / `\Class::class`), distinguishes list from
     * associative arrays, and single-quote-escapes strings.
     */
    private function export(mixed $value, int $depth): string
    {
        if ($value instanceof RawPhp) {
            return $value->code;
        }
        if (is_array($value)) {
            return $this->exportArray($value, $depth);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }

        return $this->str((string) $value);
    }

    /** @param  array<mixed>  $arr */
    private function exportArray(array $arr, int $depth): string
    {
        if ($arr === []) {
            return '[]';
        }
        $isList = array_is_list($arr);
        $pad = str_repeat('    ', $depth + 1);
        $close = str_repeat('    ', $depth);
        $lines = [];
        foreach ($arr as $k => $v) {
            $rendered = $this->export($v, $depth + 1);
            $lines[] = $isList
                ? $pad.$rendered.','
                : $pad.$this->str((string) $k).' => '.$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".$close.']';
    }

    private function str(string $s): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $s)."'";
    }
}
