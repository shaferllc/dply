<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * Suggests a richer input for common Laravel env keys — a true/false toggle
 * for booleans, a dropdown of known values for enum-like settings — so the
 * editor can offer the right control instead of a bare text box.
 *
 * Always non-destructive: the current value is folded into the option list so
 * an unexpected value is still selectable, and anything unknown falls back to
 * plain text.
 */
class SiteEnvFieldHints
{
    /** Keys whose value is boolean-ish. */
    private const BOOL_KEYS = [
        'APP_DEBUG', 'APP_MAINTENANCE', 'APP_FORCE_HTTPS',
        'SESSION_SECURE_COOKIE', 'SESSION_ENCRYPT', 'SESSION_HTTP_ONLY',
        'MAIL_VERIFY_PEER', 'DB_FOREIGN_KEYS',
        'TELESCOPE_ENABLED', 'DEBUGBAR_ENABLED', 'HORIZON_DARKMODE',
        'PULSE_ENABLED', 'SCOUT_QUEUE',
    ];

    /** key => allowed values (current value is added in front at render time). */
    private const ENUMS = [
        'APP_ENV' => ['production', 'local', 'staging', 'testing', 'development'],
        'LOG_LEVEL' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        'LOG_CHANNEL' => ['stack', 'single', 'daily', 'syslog', 'errorlog', 'stderr', 'null'],
        'LOG_STACK' => ['single', 'daily', 'stderr'],
        'DB_CONNECTION' => ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'mariadb'],
        'CACHE_STORE' => ['file', 'database', 'redis', 'memcached', 'array', 'apc'],
        'CACHE_DRIVER' => ['file', 'database', 'redis', 'memcached', 'array', 'apc'],
        'QUEUE_CONNECTION' => ['sync', 'database', 'redis', 'sqs', 'beanstalkd', 'null'],
        'SESSION_DRIVER' => ['database', 'file', 'cookie', 'redis', 'memcached', 'array'],
        'BROADCAST_CONNECTION' => ['reverb', 'pusher', 'ably', 'redis', 'log', 'null'],
        'BROADCAST_DRIVER' => ['reverb', 'pusher', 'ably', 'redis', 'log', 'null'],
        'MAIL_MAILER' => ['smtp', 'sendmail', 'mailgun', 'ses', 'postmark', 'resend', 'sendgrid', 'cloudflare', 'log', 'array', 'failover', 'roundrobin'],
        'MAIL_SCHEME' => ['smtp', 'smtps'],
        'FILESYSTEM_DISK' => ['local', 'public', 's3'],
        'REDIS_CLIENT' => ['phpredis', 'predis'],
        'DB_CHARSET' => ['utf8mb4', 'utf8'],
    ];

    /**
     * @return array{type: string, options: list<string>}
     */
    public static function hint(string $key, string $currentValue = ''): array
    {
        $key = strtoupper(trim($key));

        if (in_array($key, self::BOOL_KEYS, true) || preg_match('/_ENABLED$/', $key) === 1) {
            return ['type' => 'bool', 'options' => self::withCurrent(['true', 'false'], $currentValue)];
        }

        if (isset(self::ENUMS[$key])) {
            return ['type' => 'enum', 'options' => self::withCurrent(self::ENUMS[$key], $currentValue)];
        }

        return ['type' => 'text', 'options' => []];
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private static function withCurrent(array $options, string $currentValue): array
    {
        $currentValue = trim($currentValue);
        if ($currentValue === '' || in_array($currentValue, $options, true)) {
            return $options;
        }

        return [$currentValue, ...$options];
    }
}
