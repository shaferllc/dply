<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\ErrorTrackingCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `error_tracking` binding type (Sentry / Bugsnag / Flare). Like
 * logging this is a config binding (no provisioned resource): it injects the
 * provider's DSN/key env at deploy. The secret comes from a saved
 * {@see ErrorTrackingCredential} or the typed form.
 */
trait ManagesErrorTrackingBindings
{
    /** Supported error-tracking providers. */
    public const ERROR_TRACKING_PROVIDERS = ['sentry', 'bugsnag', 'flare'];

    /**
     * Providers whose SDK ships as a separate Composer package the app must
     * already require (deploy runs the app's own `composer install`, so dply
     * can't add it). Keyed slug → package, surfaced as a note in the modal.
     *
     * Flare's package (spatie/laravel-ignition) ships with Laravel, so it has
     * no entry here.
     *
     * @var array<string, string>
     */
    public const ERROR_TRACKING_PACKAGES = [
        'sentry' => 'sentry/sentry-laravel',
        'bugsnag' => 'bugsnag/bugsnag-laravel',
    ];

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachErrorTracking(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::ERROR_TRACKING_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported error tracking provider.'));
        }

        $creds = $this->resolveErrorTrackingCredentials($site, $provider, $params);
        $this->validateErrorTrackingCredentials($provider, $creds);

        $binding = $this->persist($site, 'error_tracking', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->errorTrackingLabel($provider, $creds),
            'target_type' => 'error_tracking',
            'target_id' => null,
            'injected_env' => $this->errorTrackingEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ]);

        $this->maybeSaveErrorTrackingCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * Resolve provider credentials: from a saved ErrorTrackingCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveErrorTrackingCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = ErrorTrackingCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof ErrorTrackingCredential) {
                throw new InvalidArgumentException(__('That saved error tracking credential is no longer available.'));
            }

            return is_array($cred->credentials) ? $cred->credentials : [];
        }

        return match ($provider) {
            'sentry' => array_filter([
                'dsn' => trim((string) ($params['dsn'] ?? '')),
                'traces_sample_rate' => trim((string) ($params['traces_sample_rate'] ?? '')),
            ], fn ($v) => $v !== ''),
            'bugsnag' => [
                'api_key' => trim((string) ($params['api_key'] ?? '')),
            ],
            'flare' => [
                'key' => trim((string) ($params['key'] ?? '')),
            ],
            default => [],
        };
    }

    /** @param  array<string, string>  $creds */
    private function validateErrorTrackingCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'sentry' => ($creds['dsn'] ?? '') === '' || ! str_starts_with(strtolower((string) ($creds['dsn'] ?? '')), 'http')
                ? throw new InvalidArgumentException(__('A valid Sentry DSN (starting with http) is required.'))
                : null,
            'bugsnag' => ($creds['api_key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Bugsnag API key is required.'))
                : null,
            'flare' => ($creds['key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Flare project key is required.'))
                : null,
            default => null,
        };
    }

    /**
     * Build the env vars the error-tracking binding injects at deploy.
     *
     * @param  array<string, string>  $creds
     * @return array<string, string>
     */
    private function errorTrackingEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'sentry' => array_filter([
                'SENTRY_LARAVEL_DSN' => ($creds['dsn'] ?? '') ?: null,
                'SENTRY_TRACES_SAMPLE_RATE' => ($creds['traces_sample_rate'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'bugsnag' => array_filter([
                'BUGSNAG_API_KEY' => ($creds['api_key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'flare' => array_filter([
                'FLARE_KEY' => ($creds['key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            default => [],
        };
    }

    /** @param  array<string, string>  $creds */
    private function errorTrackingLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'sentry' => 'Sentry',
            'bugsnag' => 'Bugsnag',
            'flare' => 'Flare',
            default => ucfirst($provider),
        };
    }

    /**
     * Persist typed credentials as a reusable ErrorTrackingCredential when the
     * operator ticked "save for reuse". No-op when reusing a saved credential or
     * when there's nothing to store.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $creds
     */
    private function maybeSaveErrorTrackingCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $labels = ['sentry' => 'Sentry', 'bugsnag' => 'Bugsnag', 'flare' => 'Flare'];
            $name = ($labels[$provider] ?? ucfirst($provider)).' '.__('project');
        }

        ErrorTrackingCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
