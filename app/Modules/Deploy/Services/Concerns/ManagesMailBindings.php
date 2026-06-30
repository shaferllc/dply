<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\MailCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `mail` binding type (single transports + failover/roundrobin
 * chains) and build the MAIL_* env it injects at deploy.
 */
trait ManagesMailBindings
{
    /**
     * Providers whose transport ships as a separate Composer package the app
     * must already require (deploy runs the app's own `composer install`, so
     * dply can't add it). Keyed slug → package, surfaced as a note in the modal
     * and as the failure signal a test-send produces when the package is absent.
     *
     * @var array<string, string>
     */
    public const MAIL_TRANSPORT_PACKAGES = [
        'mailgun' => 'symfony/mailgun-mailer',
        'postmark' => 'symfony/postmark-mailer',
        'ses' => 'aws/aws-sdk-php',
        'resend' => 'resend/resend-laravel',
        'sendgrid' => 'symfony/sendgrid-mailer',
        'cloudflare' => 'symfony/http-client',
    ];

    /** Single-transport mail providers (a failover chain is built from these). */
    public const MAIL_LEG_PROVIDERS = ['smtp', 'mailgun', 'postmark', 'ses', 'resend', 'log', 'sendgrid', 'cloudflare'];

    /**
     * Configure how the app sends mail. Like logging this is a config binding
     * (no provisioned resource): it injects the chosen MAIL_* keys. The provider
     * secret/connection comes from a saved {@see MailCredential} or the typed
     * form; the from-address/name are always per-site and entered each time.
     *
     * @param  array<string, mixed> $params
     */
    private function attachMail(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));

        // Multi-instance: blank connection = the PRIMARY (default) mailer — owns
        // MAIL_MAILER + the bare keys. A name (e.g. "marketing") is a SECONDARY
        // mailer used via Mail::mailer('marketing').
        $connection = $this->resolveInstanceConnectionName($site, 'mail', $params);
        $named = ! $this->connectionIsPrimary($connection);
        $editingId = trim((string) ($params['binding_id'] ?? ''));

        // failover / roundrobin compose several single-transport "legs"; their
        // chain ORDER lives in the app's config/mail.php (it can't be expressed
        // in env), so here we only inject MAIL_MAILER + every leg's credentials.
        if (in_array($provider, ['failover', 'roundrobin'], true)) {
            if ($named) {
                throw new InvalidArgumentException(__('A failover / round-robin chain can only be your PRIMARY mailer — leave the connection name blank.'));
            }

            return $this->attachFailoverMail($site, $provider, $params, $editingId);
        }

        if (! in_array($provider, self::MAIL_LEG_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported mail provider.'));
        }

        // A named secondary mailer must use SMTP or Log — those transports are
        // fully configurable per-mailer in config/mail.php. The API providers
        // (Mailgun, Postmark, SES, Resend, SendGrid, Cloudflare) read GLOBAL
        // config/services.php credentials, so a second account can't be expressed
        // as a named mailer; use one as the primary and add the second over SMTP.
        if ($named && ! in_array($provider, ['smtp', 'log'], true)) {
            throw new InvalidArgumentException(__('A named secondary mailer must use SMTP or Log. API providers (Mailgun, Postmark, SES, Resend, …) read global credentials — use one as your primary mailer and add the second account over SMTP.'));
        }

        $fromAddress = trim((string) ($params['from_address'] ?? ''));
        // Resolve ${APP_NAME}-style placeholders against the site env so the
        // stored MAIL_FROM_NAME / Cloudflare sender ships a real name, not a
        // literal "${APP_NAME}" (dply's API calls don't run the app's phpdotenv).
        $fromName = \App\Support\Mail\MailPlaceholderResolver::resolve($site, trim((string) ($params['from_name'] ?? '')));
        if ($provider !== 'log' && ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException(__('A valid "from" email address is required.'));
        }

        $creds = $this->resolveMailCredentials($site, $provider, $params);
        $this->validateMailCredentials($provider, $creds);

        if ($named) {
            $binding = $this->attachNamedMail($site, $connection, $provider, $creds, $fromAddress, $fromName, $editingId);
            $this->maybeSaveMailCredential($site, $provider, $params, $creds);

            return $binding;
        }

        $binding = $this->persistInstanceBinding($site, 'mail', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->mailLabel($provider, $creds),
            'target_type' => 'mail_transport',
            'target_id' => null,
            'injected_env' => $this->mailEnv($provider, $creds, $fromAddress, $fromName),
            'config' => array_filter([
                'provider' => $provider,
                'from_address' => $fromAddress ?: null,
                'from_name' => $fromName ?: null,
            ]),
            'last_error' => null,
        ], true, $editingId);

        $this->maybeSaveMailCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * A NAMED secondary mailer (SMTP/log). Injects MAIL_<NAME>_* (never
     * MAIL_MAILER — that selects the app default, owned by the primary) plus a
     * config/mail.php mailer snippet the operator pastes; the app then sends via
     * Mail::mailer('<name>'). SMTP is fully configurable per mailer, so two
     * independent SMTP accounts never collide.
     *
     * @param  array<string, mixed>  $creds
     */
    private function attachNamedMail(Site $site, string $connection, string $provider, array $creds, string $fromAddress, string $fromName, string $editingId): SiteBinding
    {
        $up = strtoupper($connection);

        $env = [];
        if ($provider === 'smtp') {
            $env = array_filter([
                "MAIL_{$up}_HOST" => ($creds['host'] ?? '') ?: null,
                "MAIL_{$up}_PORT" => ($creds['port'] ?? '') ?: null,
                "MAIL_{$up}_USERNAME" => ($creds['username'] ?? '') ?: null,
                "MAIL_{$up}_PASSWORD" => ($creds['password'] ?? '') ?: null,
                "MAIL_{$up}_ENCRYPTION" => in_array($creds['encryption'] ?? '', ['tls', 'ssl'], true) ? $creds['encryption'] : null,
                "MAIL_{$up}_SCHEME" => match ($creds['encryption'] ?? '') {
                    'ssl' => 'smtps',
                    'tls' => 'smtp',
                    default => null,
                },
            ], fn ($v) => $v !== null);
        }
        if ($fromAddress !== '') {
            $env["MAIL_{$up}_FROM_ADDRESS"] = $fromAddress;
        }
        if ($fromName !== '') {
            $env["MAIL_{$up}_FROM_NAME"] = $fromName;
        }

        return $this->persistInstanceBinding($site, 'mail', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $connection,
            'target_type' => 'mail_transport',
            'target_id' => null,
            'injected_env' => $env,
            'config' => array_filter([
                'provider' => $provider,
                'connection' => $connection,
                'from_address' => $fromAddress ?: null,
                'from_name' => $fromName ?: null,
                'connection_snippet' => $this->mailMailerSnippet($connection, $provider),
            ]),
            'last_error' => null,
        ], false, $editingId);
    }

    /** config/mail.php → 'mailers' block for a named secondary mailer (SMTP/log). */
    private function mailMailerSnippet(string $connection, string $provider): string
    {
        if ($provider === 'log') {
            return "// config/mail.php → 'mailers':\n"
                ."'{$connection}' => ['transport' => 'log'],\n"
                ."// Send via Mail::mailer('{$connection}')->send(...)";
        }

        $up = strtoupper($connection);

        return "// config/mail.php → 'mailers':\n"
            ."'{$connection}' => [\n"
            ."    'transport' => 'smtp',\n"
            ."    'host' => env('MAIL_{$up}_HOST', '127.0.0.1'),\n"
            ."    'port' => env('MAIL_{$up}_PORT', 587),\n"
            ."    'encryption' => env('MAIL_{$up}_ENCRYPTION', 'tls'),\n"
            ."    'username' => env('MAIL_{$up}_USERNAME'),\n"
            ."    'password' => env('MAIL_{$up}_PASSWORD'),\n"
            ."    'timeout' => null,\n"
            ."],\n"
            ."// Send via Mail::mailer('{$connection}')->send(...)";
    }

    /**
     * Attach a failover or round-robin mail chain: inject MAIL_MAILER=<transport>
     * plus the merged credential env of every leg, so the app's failover mailer
     * (which it must define in config/mail.php) resolves each sub-mailer. The
     * leg ORDER is shown to the operator as a config/mail.php snippet — it's the
     * one piece we can't inject.
     *
     * @param  array<string, mixed> $params
     */
    private function attachFailoverMail(Site $site, string $transport, array $params, string $editingId = ''): SiteBinding
    {
        $fromAddress = trim((string) ($params['from_address'] ?? ''));
        $fromName = \App\Support\Mail\MailPlaceholderResolver::resolve($site, trim((string) ($params['from_name'] ?? '')));
        if ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(__('A valid "from" email address is required.'));
        }

        $legsInput = is_array($params['legs'] ?? null) ? array_values($params['legs']) : [];

        $legs = [];          // ordered list of provider slugs (for config + label)
        $merged = [];        // union of every leg's transport env
        $sawSmtp = false;
        foreach ($legsInput as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            $p = strtolower(trim((string) ($leg['provider'] ?? '')));
            if (! in_array($p, self::MAIL_LEG_PROVIDERS, true)) {
                continue;
            }
            // Only one SMTP leg is possible — Laravel's `smtp` mailer reads a
            // single MAIL_HOST/PASSWORD set, so two SMTP endpoints would collide.
            if ($p === 'smtp') {
                if ($sawSmtp) {
                    throw new InvalidArgumentException(__('A failover chain can include at most one SMTP mailer (they share the MAIL_* keys).'));
                }
                $sawSmtp = true;
            }

            $creds = $this->resolveMailCredentials($site, $p, $leg);
            if ($p !== 'log') {
                $this->validateMailCredentials($p, $creds);
            }

            // Drop the per-leg MAIL_MAILER/FROM — the chain owns those.
            $legEnv = $this->mailEnv($p, $creds, '', '');
            unset($legEnv['MAIL_MAILER']);
            $merged = [...$merged, ...$legEnv];
            $legs[] = $p;
        }

        if (count($legs) < 2) {
            throw new InvalidArgumentException(__('A failover chain needs at least two mailers.'));
        }

        $injected = [
            'MAIL_MAILER' => $transport,
            ...$merged,
            'MAIL_FROM_ADDRESS' => $fromAddress,
        ];
        if ($fromName !== '') {
            $injected['MAIL_FROM_NAME'] = $fromName;
        }

        return $this->persistInstanceBinding($site, 'mail', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => ucfirst($transport).' ('.implode(' → ', $legs).')',
            'target_type' => 'mail_transport',
            'target_id' => null,
            'injected_env' => $injected,
            'config' => array_filter([
                'provider' => $transport,
                'legs' => $legs,
                'from_address' => $fromAddress ?: null,
                'from_name' => $fromName ?: null,
            ]),
            'last_error' => null,
        ], true, $editingId);
    }

    /**
     * Resolve transport credentials: from a saved MailCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveMailCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = MailCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof MailCredential) {
                throw new InvalidArgumentException(__('That saved mail credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return match ($provider) {
            'smtp' => [
                'host' => trim((string) ($params['host'] ?? '')),
                'port' => trim((string) ($params['port'] ?? '587')),
                'username' => trim((string) ($params['username'] ?? '')),
                'password' => (string) ($params['password'] ?? ''),
                'encryption' => strtolower(trim((string) ($params['encryption'] ?? 'tls'))),
            ],
            'mailgun' => [
                'secret' => trim((string) ($params['secret'] ?? '')),
                'domain' => trim((string) ($params['domain'] ?? '')),
                'endpoint' => trim((string) ($params['endpoint'] ?? 'api.mailgun.net')),
            ],
            'postmark' => [
                'token' => trim((string) ($params['token'] ?? '')),
            ],
            'ses' => [
                'access_key_id' => trim((string) ($params['access_key_id'] ?? '')),
                'secret_access_key' => trim((string) ($params['secret_access_key'] ?? '')),
                'region' => trim((string) ($params['region'] ?? '')),
            ],
            'resend' => [
                'key' => trim((string) ($params['key'] ?? '')),
            ],
            'sendgrid' => [
                'api_key' => trim((string) ($params['api_key'] ?? '')),
            ],
            'cloudflare' => [
                'account_id' => trim((string) ($params['account_id'] ?? '')),
                'key' => trim((string) ($params['key'] ?? '')),
            ],
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function validateMailCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'smtp' => ($creds['host'] ?? '') === ''
                ? throw new InvalidArgumentException(__('SMTP host is required.'))
                : null,
            'mailgun' => (($creds['secret'] ?? '') === '' || ($creds['domain'] ?? '') === '')
                ? throw new InvalidArgumentException(__('Mailgun secret and domain are required.'))
                : null,
            'postmark' => ($creds['token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Postmark server token is required.'))
                : null,
            'ses' => (($creds['access_key_id'] ?? '') === '' || ($creds['secret_access_key'] ?? '') === '' || ($creds['region'] ?? '') === '')
                ? throw new InvalidArgumentException(__('SES access key, secret, and region are required.'))
                : null,
            'resend' => ($creds['key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Resend API key is required.'))
                : null,
            'sendgrid' => ($creds['api_key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('SendGrid API key is required.'))
                : null,
            'cloudflare' => (function () use ($creds): null {
                $accountId = trim((string) ($creds['account_id'] ?? ''));
                if ($accountId === '' || ($creds['key'] ?? '') === '') {
                    throw new InvalidArgumentException(__('Cloudflare account ID and API key are required.'));
                }
                // Cloudflare account IDs are the 32-char hex identifier — catch a
                // pasted account *name* here instead of a cryptic "Could not route
                // to /accounts/<name>/…" failure when mail actually sends.
                if (! preg_match('/^[0-9a-f]{32}$/i', $accountId)) {
                    throw new InvalidArgumentException(__('The Cloudflare Account ID must be the 32-character hex identifier from your Cloudflare dashboard (Account Home → Account ID) — not an account name.'));
                }

                return null;
            })(),
            default => null,
        };
    }

    /**
     * Build the MAIL_* vars the mail binding injects at deploy. MAIL_MAILER
     * selects the transport; the provider-specific keys carry the secret; the
     * from-address/name are shared across providers.
     *
     * Note: `ses` reuses the AWS_* keys the object-storage binding also injects
     * — they are genuinely shared by the AWS SDK, so a site using both SES mail
     * and S3 storage must point both at the same AWS account (surfaced in the
     * modal copy rather than namespaced here).
     *
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function mailEnv(string $provider, array $creds, string $fromAddress, string $fromName): array
    {
        $shared = array_filter([
            'MAIL_FROM_ADDRESS' => $fromAddress !== '' ? $fromAddress : null,
            'MAIL_FROM_NAME' => $fromName !== '' ? $fromName : null,
        ], fn ($v) => $v !== null);

        $transport = match ($provider) {
            'smtp' => array_filter([
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => ($creds['host'] ?? '') ?: null,
                'MAIL_PORT' => ($creds['port'] ?? '') ?: null,
                'MAIL_USERNAME' => ($creds['username'] ?? '') ?: null,
                'MAIL_PASSWORD' => ($creds['password'] ?? '') ?: null,
                // Laravel 11 reads MAIL_SCHEME (smtp/smtps); Laravel ≤10 reads
                // MAIL_ENCRYPTION (tls/ssl). Inject both so the binding works on
                // either generation — the unused key is harmlessly ignored.
                //   tls → STARTTLS  (scheme smtp,  encryption tls)
                //   ssl → implicit  (scheme smtps, encryption ssl)
                'MAIL_ENCRYPTION' => in_array($creds['encryption'] ?? '', ['tls', 'ssl'], true) ? $creds['encryption'] : null,
                'MAIL_SCHEME' => match ($creds['encryption'] ?? '') {
                    'ssl' => 'smtps',
                    'tls' => 'smtp',
                    default => null,
                },
            ], fn ($v) => $v !== null),
            'mailgun' => array_filter([
                'MAIL_MAILER' => 'mailgun',
                'MAILGUN_DOMAIN' => ($creds['domain'] ?? '') ?: null,
                'MAILGUN_SECRET' => ($creds['secret'] ?? '') ?: null,
                'MAILGUN_ENDPOINT' => ($creds['endpoint'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'postmark' => array_filter([
                'MAIL_MAILER' => 'postmark',
                'POSTMARK_TOKEN' => ($creds['token'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'ses' => array_filter([
                'MAIL_MAILER' => 'ses',
                'AWS_ACCESS_KEY_ID' => ($creds['access_key_id'] ?? '') ?: null,
                'AWS_SECRET_ACCESS_KEY' => ($creds['secret_access_key'] ?? '') ?: null,
                'AWS_DEFAULT_REGION' => ($creds['region'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'resend' => array_filter([
                'MAIL_MAILER' => 'resend',
                'RESEND_KEY' => ($creds['key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'sendgrid' => array_filter([
                'MAIL_MAILER' => 'sendgrid',
                'SENDGRID_API_KEY' => ($creds['api_key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            // Cloudflare's API mailer (Laravel 13+): account_id + key, read via
            // config/services.php → CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_KEY.
            'cloudflare' => array_filter([
                'MAIL_MAILER' => 'cloudflare',
                'CLOUDFLARE_ACCOUNT_ID' => ($creds['account_id'] ?? '') ?: null,
                'CLOUDFLARE_KEY' => ($creds['key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'log' => ['MAIL_MAILER' => 'log'],
            default => [],
        };

        return [...$transport, ...$shared];
    }

    /** @param  array<string, mixed> $creds */
    private function mailLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'smtp' => 'SMTP'.(($creds['host'] ?? '') !== '' ? ' '.$creds['host'] : ''),
            'mailgun' => 'Mailgun'.(($creds['domain'] ?? '') !== '' ? ' '.$creds['domain'] : ''),
            'postmark' => 'Postmark',
            'ses' => 'Amazon SES'.(($creds['region'] ?? '') !== '' ? ' ('.$creds['region'].')' : ''),
            'resend' => 'Resend',
            'sendgrid' => 'SendGrid',
            'cloudflare' => 'Cloudflare',
            'log' => 'Log (no delivery)',
            default => $provider,
        };
    }

    /**
     * Persist typed credentials as a reusable MailCredential when the operator
     * ticked "save for reuse". No-op when reusing a saved credential or for the
     * `log` provider (no credentials to store).
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveMailCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($provider === 'log' || $creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $labels = ['smtp' => 'SMTP', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'ses' => 'Amazon SES', 'resend' => 'Resend'];
            $name = ($labels[$provider] ?? ucfirst($provider)).' '.__('mail keys');
        }

        MailCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
