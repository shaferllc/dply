<?php

declare(strict_types=1);

namespace App\Services\Sites;

/**
 * Sanity-checks a site's environment variables and returns human-readable
 * warnings — the kind of misconfiguration that doesn't stop a deploy but
 * bites in production (debug left on, missing app key, plaintext URLs,
 * placeholder secrets, mail going to the log, …).
 *
 * Pure + stateless: give it the parsed KEY => value map, get findings back.
 * Each finding is { level: danger|warn|info, key: ?string, message }.
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
        $hasEnv = $get('APP_ENV') !== null && $get('APP_ENV') !== '';

        $findings = [];

        // APP_KEY — without it Laravel can't encrypt cookies/sessions and 500s
        // before it can even render an error page.
        $appKey = $get('APP_KEY');
        if ($appKey === null || $appKey === '') {
            $findings[] = ['level' => 'danger', 'key' => 'APP_KEY', 'message' => __('APP_KEY is empty — the app cannot encrypt sessions/cookies and will error. Generate one.')];
        }

        // Debug in production is the classic foot-gun: stack traces + env dumps
        // leak to the public.
        if ($this->isTruthy($get('APP_DEBUG'))) {
            if ($isProd) {
                $findings[] = ['level' => 'danger', 'key' => 'APP_DEBUG', 'message' => __('APP_DEBUG is true while APP_ENV is production — this exposes stack traces and secrets to visitors. Set it to false.')];
            } else {
                $findings[] = ['level' => 'warn', 'key' => 'APP_DEBUG', 'message' => __('APP_DEBUG is true — fine for local, but make sure it is false in production.')];
            }
        }

        // A deployed site running as APP_ENV=local is almost always a mistake.
        if ($hasEnv && in_array(strtolower((string) $get('APP_ENV')), ['local', 'development', 'dev'], true)) {
            $findings[] = ['level' => 'warn', 'key' => 'APP_ENV', 'message' => __('APP_ENV is ":env" on a deployed server — production traffic usually wants APP_ENV=production.', ['env' => $get('APP_ENV')])];
        }

        // Plaintext app URL in prod → insecure cookies, mixed content, bad links.
        $appUrl = (string) $get('APP_URL');
        if ($appUrl !== '' && str_starts_with(strtolower($appUrl), 'http://') && $isProd) {
            $findings[] = ['level' => 'warn', 'key' => 'APP_URL', 'message' => __('APP_URL uses http:// in production — generated links and cookies should be https://.')];
        }

        // Secure-cookie off in prod.
        if ($isProd && $this->isFalsy($get('SESSION_SECURE_COOKIE'))) {
            $findings[] = ['level' => 'warn', 'key' => 'SESSION_SECURE_COOKIE', 'message' => __('SESSION_SECURE_COOKIE is false in production — session cookies will be sent over plain HTTP.')];
        }

        // Mail to the log in prod = silently dropped customer email.
        if ($isProd && in_array(strtolower((string) $get('MAIL_MAILER')), ['log', 'array'], true)) {
            $findings[] = ['level' => 'warn', 'key' => 'MAIL_MAILER', 'message' => __('MAIL_MAILER is ":m" in production — outbound mail is not actually delivered.', ['m' => $get('MAIL_MAILER')])];
        }

        // Empty DB password on a non-sqlite connection.
        $dbConn = strtolower((string) $get('DB_CONNECTION'));
        if ($dbConn !== '' && $dbConn !== 'sqlite' && ($get('DB_PASSWORD') === '' || $get('DB_PASSWORD') === null)) {
            $findings[] = ['level' => 'warn', 'key' => 'DB_PASSWORD', 'message' => __('DB_PASSWORD is empty for a :c database — set a password unless the server enforces socket/peer auth.', ['c' => $dbConn])];
        }

        // Placeholder-looking secrets the operator forgot to replace.
        foreach ($vars as $key => $value) {
            $val = strtolower(trim((string) $value));
            if ($val !== '' && $this->looksSecret((string) $key) && in_array($val, self::PLACEHOLDERS, true)) {
                $findings[] = ['level' => 'warn', 'key' => (string) $key, 'message' => __(':key looks like a placeholder value (":v") — replace it with the real secret.', ['key' => $key, 'v' => $value])];
            }
        }

        return $findings;
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
