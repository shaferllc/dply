<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\Site;
use App\Models\SiteBinding;
use InvalidArgumentException;

/**
 * Attach the `session` config binding (injects the full SESSION_* set).
 */
trait ManagesSessionBindings
{
    /** Framework defaults (config/session.php) materialized for a blank field. */
    private const SESSION_DEFAULTS = [
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT' => 'false',
        'SESSION_PATH' => '/',
        'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_HTTP_ONLY' => 'true',
        'SESSION_SAME_SITE' => 'lax',
    ];

    /**
     * Configure how sessions are stored + how the session cookie behaves. Like
     * queue/cache this is a config binding (no attached resource): it injects
     * the chosen SESSION_* keys. Every field is optional — a blank one is left
     * out so the framework default applies; redis needs the Redis binding too.
     *
     * @param  array<string, mixed> $params
     */
    private function attachSession(Site $site, array $params): SiteBinding
    {
        // Inject the FULL session config: every SESSION_* key gets a value —
        // the operator's choice, or the framework default when left blank — so
        // the binding is one complete, explicit snapshot rather than a partial
        // set of overrides.
        $raw = [
            'SESSION_DRIVER' => strtolower(trim((string) ($params['driver'] ?? ''))),
            'SESSION_LIFETIME' => trim((string) ($params['lifetime'] ?? '')),
            'SESSION_ENCRYPT' => trim((string) ($params['encrypt'] ?? '')),
            'SESSION_PATH' => trim((string) ($params['path'] ?? '')),
            'SESSION_DOMAIN' => trim((string) ($params['domain'] ?? '')),
            'SESSION_SECURE_COOKIE' => trim((string) ($params['secure_cookie'] ?? '')),
            'SESSION_HTTP_ONLY' => trim((string) ($params['http_only'] ?? '')),
            'SESSION_SAME_SITE' => trim((string) ($params['same_site'] ?? '')),
        ];

        $injected = [];
        foreach (self::SESSION_DEFAULTS as $key => $default) {
            $injected[$key] = $raw[$key] === '' ? $default : $raw[$key];
        }

        if (! in_array($injected['SESSION_DRIVER'], ['file', 'database', 'cookie', 'redis', 'memcached', 'array'], true)) {
            throw new InvalidArgumentException(__('Unsupported session driver.'));
        }
        $this->assertDriverDependency($site, __('sessions'), $injected['SESSION_DRIVER']);
        if (! ctype_digit($injected['SESSION_LIFETIME'])) {
            throw new InvalidArgumentException(__('Session lifetime must be a whole number of minutes.'));
        }
        if (! str_starts_with($injected['SESSION_PATH'], '/')) {
            throw new InvalidArgumentException(__('Session cookie path must start with "/".'));
        }

        return $this->persist($site, 'session', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'session-'.$injected['SESSION_DRIVER'],
            'target_type' => 'session_driver',
            'target_id' => null,
            'injected_env' => $injected,
            // Persist the RAW field values (blank where the operator defaulted) so
            // re-opening the modal shows their choices with placeholders standing
            // in for the blanks, not the materialized defaults.
            'config' => [
                'driver' => $raw['SESSION_DRIVER'],
                'lifetime' => $raw['SESSION_LIFETIME'],
                'encrypt' => $raw['SESSION_ENCRYPT'],
                'path' => $raw['SESSION_PATH'],
                'domain' => $raw['SESSION_DOMAIN'],
                'secure_cookie' => $raw['SESSION_SECURE_COOKIE'],
                'http_only' => $raw['SESSION_HTTP_ONLY'],
                'same_site' => $raw['SESSION_SAME_SITE'],
            ],
        ]);
    }
}
