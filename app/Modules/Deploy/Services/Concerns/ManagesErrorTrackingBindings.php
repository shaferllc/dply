<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\ErrorTrackingCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\LookoutProvisioner;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `error_tracking` binding type (Sentry / Bugsnag / Flare / Lookout).
 * Like logging this is a config binding: it injects the provider's DSN/key env
 * at deploy. The secret comes from a saved {@see ErrorTrackingCredential} or the
 * typed form.
 *
 * Lookout is special: as well as "attach an existing DSN" it supports a
 * one-click *provision* path ({@see provisionLookout()}) that creates the
 * project on uselookout.app via {@see LookoutProvisioner} and injects the
 * returned DSN — no DSN to paste.
 */
trait ManagesErrorTrackingBindings
{
    /** Supported error-tracking providers. */
    public const ERROR_TRACKING_PROVIDERS = ['sentry', 'bugsnag', 'flare', 'lookout'];

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
        'lookout' => 'lookout/tracing',
    ];

    /**
     * @param  array<string, mixed> $params
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
     * Provision entry point for the error-tracking binding. Only Lookout has a
     * real resource to create (a project on uselookout.app); every other
     * provider has nothing to spin up, so "provision" is just attach.
     *
     * @param  array<string, mixed> $params
     */
    private function provisionErrorTracking(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));

        return $provider === 'lookout'
            ? $this->provisionLookout($site, $params)
            : $this->attachErrorTracking($site, $params);
    }

    /**
     * One-click Lookout: create the project on the customer's uselookout.app
     * account via {@see LookoutProvisioner} (their Lookout API token + org), then
     * persist the returned DSN as the binding's injected env. The DSN carries the
     * ingest key, so this is all the app needs to start reporting once the
     * `lookout/tracing` package is present (installed separately on the box).
     *
     * @param  array<string, mixed> $params
     */
    private function provisionLookout(Site $site, array $params): SiteBinding
    {
        $name = trim((string) ($params['project_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($site->name ?: $site->slug ?: 'dply');
        }

        $managed = config('services.lookout.account_model') === 'managed';
        $provisioner = app(LookoutProvisioner::class);

        if ($managed) {
            // dply-managed: no per-customer token; dply mints the project under
            // its own org via the service token. The org is configured on dply
            // (managed_organization_id) or resolved by Lookout.
            $result = $provisioner->provisionManaged($name);
            $token = '';
            $org = (string) config('services.lookout.managed_organization_id', '');
        } else {
            $token = trim((string) ($params['lookout_token'] ?? ''));
            $org = trim((string) ($params['lookout_org'] ?? ''));
            if ($token === '') {
                throw new InvalidArgumentException(__('Paste your Lookout API token to create a project.'));
            }
            if ($org === '') {
                throw new InvalidArgumentException(__('Choose the Lookout organization to create the project in.'));
            }

            $result = $provisioner->provision($token, $org, $name);
        }

        $binding = $this->persist($site, 'error_tracking', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'Lookout · '.$result['project_name'],
            'target_type' => 'error_tracking',
            'target_id' => $result['project_id'],
            'injected_env' => $this->errorTrackingEnv('lookout', ['dsn' => $result['dsn']]),
            'config' => array_filter([
                'provider' => 'lookout',
                'project_id' => $result['project_id'],
                'project_name' => $result['project_name'],
                'organization_id' => $org,
            ], fn ($v) => $v !== null && $v !== ''),
            'last_error' => null,
        ]);

        // BYO model only: when the operator opts in (save_credential), persist
        // the Lookout API token (not the per-project key) for reuse — it's the
        // reusable secret, so the next site can mint its own project without
        // re-pasting it. The managed model has no per-customer token to save.
        if (! $managed && $token !== '') {
            $this->maybeSaveErrorTrackingCredential($site, 'lookout', $params, ['token' => $token, 'organization_id' => $org]);
        }

        return $binding;
    }

    /**
     * Resolve provider credentials: from a saved ErrorTrackingCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed> $params
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

            return ($cred->credentials );
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
            // Attach-existing Lookout: paste a DSN (the provision path mints one
            // instead and never reaches here).
            'lookout' => [
                'dsn' => trim((string) ($params['dsn'] ?? '')),
            ],
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
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
            'lookout' => ($creds['dsn'] ?? '') === '' || ! str_starts_with(strtolower((string) ($creds['dsn'] ?? '')), 'http')
                ? throw new InvalidArgumentException(__('A valid Lookout DSN (starting with http) is required — or use "Create a project" to generate one.'))
                : null,
            default => null,
        };
    }

    /**
     * Build the env vars the error-tracking binding injects at deploy.
     *
     * @param  array<string, mixed> $creds
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
            // The lookout/tracing SDK reads LOOKOUT_DSN (it parses both the
            // ingest key and base URI out of it). LOOKOUT_LARAVEL=true turns on
            // the quick-start (errors + tracing + logs) so the app reports with
            // zero extra config once the package is installed.
            'lookout' => array_filter([
                'LOOKOUT_DSN' => ($creds['dsn'] ?? '') ?: null,
                'LOOKOUT_LARAVEL' => ($creds['dsn'] ?? '') !== '' ? 'true' : null,
            ], fn ($v) => $v !== null),
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function errorTrackingLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'sentry' => 'Sentry',
            'bugsnag' => 'Bugsnag',
            'flare' => 'Flare',
            'lookout' => 'Lookout',
            default => ucfirst($provider),
        };
    }

    /**
     * Persist typed credentials as a reusable ErrorTrackingCredential when the
     * operator ticked "save for reuse". No-op when reusing a saved credential or
     * when there's nothing to store.
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
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
            $labels = ['sentry' => 'Sentry', 'bugsnag' => 'Bugsnag', 'flare' => 'Flare', 'lookout' => 'Lookout'];
            $suffix = $provider === 'lookout' ? __('API token') : __('project');
            $name = ($labels[$provider] ?? ucfirst($provider)).' '.$suffix;
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
