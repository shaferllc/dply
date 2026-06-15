<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\TestSiteHealthJob;

/**
 * Sanity-checks a site's environment variables and returns human-readable
 * warnings — the kind of misconfiguration that breaks (or silently degrades) a
 * deployed app. Two severities:
 *
 *   danger — breaks the request path (the app 500s on every request): empty
 *            APP_KEY, a service pointed at an unconfigured backend, a
 *            broadcaster/driver with null credentials, …
 *   warn   — works until the feature is used, then fails or behaves wrong:
 *            mail to the log in prod, S3 disk without keys, http:// URLs, …
 *
 * Pure + stateless: give it the parsed KEY => value map, get findings back.
 * Each finding is { level: danger|warn|info, key: ?string, message }.
 *
 * Scope: only what's derivable from the env map. Server-state traps (stale
 * config cache, un-built Vite assets, missing PHP extensions) are checked by
 * {@see TestSiteHealthJob}, not here.
 */
class SiteEnvValidator
{
    /** Values that scream "I'm a leftover placeholder, not a real secret". */
    private const PLACEHOLDERS = [
        'changeme', 'change-me', 'your-key-here', 'your-secret-here', 'xxxxx',
        'todo', 'replace-me', 'placeholder', 'secret', 'password', 'example',
    ];

    /**
     * @param  array<string, string>  $vars
     * @return list<array{level: string, key: ?string, message: string}>
     */
    public function validate(array $vars): array
    {
        $get = static fn (string $k): ?string => array_key_exists($k, $vars) ? trim((string) $vars[$k]) : null;
        $isProd = in_array(strtolower((string) $get('APP_ENV')), ['production', 'prod'], true);

        return [
            ...$this->checkApp($get, $isProd),
            ...$this->checkDatabase($get),
            ...$this->checkRedisAndCache($get),
            ...$this->checkQueue($get),
            ...$this->checkMail($get, $isProd),
            ...$this->checkFilesystem($get),
            ...$this->checkBroadcast($get),
            ...$this->checkSearch($get),
            ...$this->checkSession($get, $isProd),
            ...$this->checkPlaceholders($vars),
        ];
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkApp(\Closure $get, bool $isProd): array
    {
        $findings = [];

        // APP_KEY — without it Laravel can't encrypt cookies/sessions and 500s
        // before it can even render an error page.
        $appKey = $get('APP_KEY');
        if ($appKey === null || $appKey === '') {
            $findings[] = $this->danger('APP_KEY', __('APP_KEY is empty — the app cannot encrypt sessions/cookies and will error. Generate one.'));
        } elseif (! $this->looksLikeValidAppKey($appKey)) {
            $findings[] = $this->danger('APP_KEY', __('APP_KEY is set but not a valid "base64:" 32-byte key — encryption will fail. Regenerate it.'));
        }

        // Debug in production is the classic foot-gun: stack traces + env dumps
        // leak to the public. But it is a SECURITY warning, not a danger: the app
        // boots and serves every request fine with APP_DEBUG=true — it just
        // over-shares on errors. "danger" is reserved for env that breaks the
        // request path (empty APP_KEY, null broadcaster creds, …) and hard-blocks
        // the write; debug-in-prod is a deliberate, reversible operator choice
        // (e.g. briefly turning it on to diagnose a 500), so it must warn, not
        // refuse. Keep the wording loud so it still stands out in the push report.
        if ($this->isTruthy($get('APP_DEBUG'))) {
            $findings[] = $isProd
                ? $this->warn('APP_DEBUG', __('APP_DEBUG is true while APP_ENV is production — this exposes stack traces and secrets to visitors. Turn it on only to debug, then set it back to false.'))
                : $this->warn('APP_DEBUG', __('APP_DEBUG is true — fine for local, but make sure it is false in production.'));
        }

        // A deployed site running as APP_ENV=local is almost always a mistake.
        $env = strtolower((string) $get('APP_ENV'));
        if ($env !== '' && in_array($env, ['local', 'development', 'dev'], true)) {
            $findings[] = $this->warn('APP_ENV', __('APP_ENV is ":env" on a deployed server — production traffic usually wants APP_ENV=production.', ['env' => $get('APP_ENV')]));
        }

        // APP_URL drives generated links, signed URLs, emails, queued asset
        // URLs and broadcasting — wrong/empty value breaks all of them.
        $appUrl = (string) $get('APP_URL');
        if ($appUrl === '') {
            $findings[] = $this->warn('APP_URL', __('APP_URL is empty — generated links, emails, signed URLs and assets will point at localhost. Set it to the site\'s URL.'));
        } elseif (str_starts_with(strtolower($appUrl), 'http://') && $isProd) {
            $findings[] = $this->warn('APP_URL', __('APP_URL uses http:// in production — generated links and cookies should be https://.'));
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkDatabase(\Closure $get): array
    {
        $findings = [];

        $conn = strtolower((string) $get('DB_CONNECTION'));

        // A connection-string DATABASE_URL satisfies all of DB_HOST/DB_DATABASE/…
        $hasUrl = $this->filled($get('DATABASE_URL'));

        // Whether anything actually needs the database this request.
        $usesDbBackend = in_array('database', [
            strtolower((string) $get('SESSION_DRIVER')),
            strtolower((string) $get('CACHE_STORE')),
            strtolower((string) $get('QUEUE_CONNECTION')),
        ], true);

        if ($conn === '' || $conn === 'sqlite') {
            // SQLite or unset → no host/credentials to validate. If services
            // route through "database" but no connection is chosen at all, that
            // still resolves to sqlite by Laravel default, which is rarely what
            // a deployed app wants — nudge only when database is relied on.
            if ($conn === '' && $usesDbBackend) {
                $findings[] = $this->warn('DB_CONNECTION', __('Session/cache/queue use the "database" driver but DB_CONNECTION is unset — Laravel falls back to SQLite, which usually isn\'t intended in production.'));
            }

            return $findings;
        }

        if (! $hasUrl) {
            if (! $this->filled($get('DB_HOST'))) {
                $findings[] = $this->danger('DB_HOST', __('DB_HOST is empty for a :c database — the app cannot connect and will error on the first query (and on every request if session/cache use the database).', ['c' => $conn]));
            }
            if (! $this->filled($get('DB_DATABASE'))) {
                $findings[] = $this->danger('DB_DATABASE', __('DB_DATABASE is empty for a :c database — no schema to connect to.', ['c' => $conn]));
            }
            if (! $this->filled($get('DB_USERNAME'))) {
                $findings[] = $this->warn('DB_USERNAME', __('DB_USERNAME is empty for a :c database — set it unless the server uses socket/peer auth.', ['c' => $conn]));
            }
            if (! $this->filled($get('DB_PASSWORD'))) {
                $findings[] = $this->warn('DB_PASSWORD', __('DB_PASSWORD is empty for a :c database — set a password unless the server enforces socket/peer auth.', ['c' => $conn]));
            }
        }

        // Port that belongs to a different engine → almost always a copy-paste slip.
        $port = (string) $get('DB_PORT');
        if ($port !== '' && ! ctype_digit($port)) {
            $findings[] = $this->warn('DB_PORT', __('DB_PORT ":p" is not a number.', ['p' => $port]));
        } elseif (in_array($conn, ['pgsql'], true) && $port === '3306') {
            $findings[] = $this->warn('DB_PORT', __('DB_PORT is 3306 (MySQL) but DB_CONNECTION is pgsql — Postgres usually listens on 5432.'));
        } elseif (in_array($conn, ['mysql', 'mariadb'], true) && $port === '5432') {
            $findings[] = $this->warn('DB_PORT', __('DB_PORT is 5432 (Postgres) but DB_CONNECTION is :c — MySQL/MariaDB usually listens on 3306.', ['c' => $conn]));
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkRedisAndCache(\Closure $get): array
    {
        $findings = [];

        $usesRedis = in_array('redis', [
            strtolower((string) $get('CACHE_STORE')),
            strtolower((string) $get('CACHE_DRIVER')),
            strtolower((string) $get('SESSION_DRIVER')),
            strtolower((string) $get('QUEUE_CONNECTION')),
            strtolower((string) $get('BROADCAST_CONNECTION')),
            strtolower((string) $get('BROADCAST_DRIVER')),
        ], true);

        if ($usesRedis && ! $this->filled($get('REDIS_HOST')) && ! $this->filled($get('REDIS_URL'))) {
            $findings[] = $this->danger('REDIS_HOST', __('A driver is set to "redis" (cache/session/queue/broadcast) but neither REDIS_HOST nor REDIS_URL is set — the connection fails on the first use.'));
        }

        $port = (string) $get('REDIS_PORT');
        if ($port !== '' && ! ctype_digit($port)) {
            $findings[] = $this->warn('REDIS_PORT', __('REDIS_PORT ":p" is not a number.', ['p' => $port]));
        }

        if (strtolower((string) $get('CACHE_STORE')) === 'memcached' && ! $this->filled($get('MEMCACHED_HOST'))) {
            $findings[] = $this->warn('MEMCACHED_HOST', __('CACHE_STORE is memcached but MEMCACHED_HOST is empty — the cache cannot connect.'));
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkQueue(\Closure $get): array
    {
        $driver = strtolower((string) $get('QUEUE_CONNECTION'));

        if ($driver === 'sqs' && (! $this->filled($get('SQS_KEY')) && ! $this->filled($get('AWS_ACCESS_KEY_ID')))) {
            return [$this->warn('SQS_KEY', __('QUEUE_CONNECTION is sqs but no SQS/AWS credentials are set — jobs cannot be pushed to the queue.'))];
        }

        if ($driver === 'beanstalkd' && ! $this->filled($get('BEANSTALKD_HOST')) && ! $this->filled($get('QUEUE_HOST'))) {
            return [$this->warn('BEANSTALKD_HOST', __('QUEUE_CONNECTION is beanstalkd but no host is configured — the queue cannot connect.'))];
        }

        return [];
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkMail(\Closure $get, bool $isProd): array
    {
        $findings = [];
        $mailer = strtolower((string) $get('MAIL_MAILER'));

        if ($isProd && in_array($mailer, ['log', 'array'], true)) {
            $findings[] = $this->warn('MAIL_MAILER', __('MAIL_MAILER is ":m" in production — outbound mail is not actually delivered.', ['m' => $get('MAIL_MAILER')]));
        }

        $needs = match ($mailer) {
            'smtp' => ['MAIL_HOST'],
            'mailgun' => ['MAILGUN_DOMAIN', 'MAILGUN_SECRET'],
            'postmark' => ['POSTMARK_TOKEN'],
            'resend' => ['RESEND_KEY'],
            'sendgrid' => ['SENDGRID_API_KEY'],
            'cloudflare' => ['CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_KEY'],
            'ses' => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'],
            default => [],
        };
        foreach ($needs as $key) {
            if (! $this->filled($get($key))) {
                $findings[] = $this->warn($key, __(':key is empty while MAIL_MAILER=:m — outbound mail will fail when the app tries to send.', ['key' => $key, 'm' => $mailer]));
            }
        }

        if ($mailer !== '' && ! in_array($mailer, ['log', 'array'], true) && ! $this->filled($get('MAIL_FROM_ADDRESS'))) {
            $findings[] = $this->warn('MAIL_FROM_ADDRESS', __('MAIL_FROM_ADDRESS is empty — sending mail will throw "address is empty".'));
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkFilesystem(\Closure $get): array
    {
        $disk = strtolower((string) $get('FILESYSTEM_DISK'));
        if (! in_array($disk, ['s3', 'spaces', 'r2', 'digitalocean'], true)) {
            return [];
        }

        $findings = [];
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_BUCKET'] as $key) {
            if (! $this->filled($get($key))) {
                $findings[] = $this->warn($key, __(':key is empty while FILESYSTEM_DISK is an S3-style disk — file storage will fail when used.', ['key' => $key]));
            }
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkBroadcast(\Closure $get): array
    {
        $broadcast = strtolower((string) ($get('BROADCAST_CONNECTION') ?? $get('BROADCAST_DRIVER')));

        // The broadcast manager constructs the driver (Pusher/Reverb/Ably)
        // eagerly, so a null key/secret throws on boot — a classic
        // "deployed but won't load".
        $needs = match ($broadcast) {
            'reverb' => ['REVERB_APP_KEY', 'REVERB_APP_ID', 'REVERB_APP_SECRET'],
            'pusher' => ['PUSHER_APP_KEY', 'PUSHER_APP_ID', 'PUSHER_APP_SECRET'],
            'ably' => ['ABLY_KEY'],
            default => [],
        };

        $findings = [];
        foreach ($needs as $key) {
            if (! $this->filled($get($key))) {
                $findings[] = $this->danger($key, __(':key is empty while BROADCAST_CONNECTION=:b — the broadcaster cannot be constructed and the app errors on every request. Set it (then clear the config cache or redeploy). If you don\'t use broadcasting, set BROADCAST_CONNECTION=log.', ['key' => $key, 'b' => $broadcast]));
            }
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkSearch(\Closure $get): array
    {
        $driver = strtolower((string) $get('SCOUT_DRIVER'));

        $needs = match ($driver) {
            'algolia' => ['ALGOLIA_APP_ID', 'ALGOLIA_SECRET'],
            'meilisearch' => ['MEILISEARCH_HOST'],
            'typesense' => ['TYPESENSE_API_KEY'],
            default => [],
        };

        $findings = [];
        foreach ($needs as $key) {
            if (! $this->filled($get($key))) {
                $findings[] = $this->warn($key, __(':key is empty while SCOUT_DRIVER=:d — search indexing/queries will fail.', ['key' => $key, 'd' => $driver]));
            }
        }

        return $findings;
    }

    /**
     * @param  \Closure(string): ?string  $get
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkSession(\Closure $get, bool $isProd): array
    {
        if ($isProd && $this->isFalsy($get('SESSION_SECURE_COOKIE'))) {
            return [$this->warn('SESSION_SECURE_COOKIE', __('SESSION_SECURE_COOKIE is false in production — session cookies will be sent over plain HTTP.'))];
        }

        return [];
    }

    /**
     * @param  array<string, string>  $vars
     * @return list<array{level: string, key: ?string, message: string}>
     */
    private function checkPlaceholders(array $vars): array
    {
        $findings = [];
        foreach ($vars as $key => $value) {
            $val = strtolower(trim((string) $value));
            if ($val !== '' && $this->looksSecret((string) $key) && in_array($val, self::PLACEHOLDERS, true)) {
                $findings[] = $this->warn((string) $key, __(':key looks like a placeholder value (":v") — replace it with the real secret.', ['key' => $key, 'v' => $value]));
            }
        }

        return $findings;
    }

    /**
     * @return array{level: string, key: ?string, message: string}
     */
    private function danger(?string $key, string $message): array
    {
        return ['level' => 'danger', 'key' => $key, 'message' => $message];
    }

    /**
     * @return array{level: string, key: ?string, message: string}
     */
    private function warn(?string $key, string $message): array
    {
        return ['level' => 'warn', 'key' => $key, 'message' => $message];
    }

    private function filled(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
    }

    private function looksLikeValidAppKey(string $key): bool
    {
        if (! str_starts_with($key, 'base64:')) {
            // Plain 32-char keys are technically valid for AES-256; accept them.
            return strlen($key) >= 16;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return $decoded !== false && in_array(strlen($decoded), [16, 24, 32], true);
    }

    private function isTruthy(?string $v): bool
    {
        return $v !== null && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    private function isFalsy(?string $v): bool
    {
        return $v !== null && in_array(strtolower(trim($v)), ['0', 'false', 'off', 'no'], true);
    }

    private function looksSecret(string $key): bool
    {
        return (bool) preg_match('/(KEY|SECRET|TOKEN|PASSWORD|PASS|CREDENTIAL|PRIVATE)/i', $key);
    }
}
