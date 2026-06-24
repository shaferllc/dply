<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ObjectStorageCredential;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Deploy\Services\DeploymentSecretInventory;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsSiteBindingFormDefaults
{


    /**
     * @return array<string, mixed>
     */
    private function defaultBindingForm(string $type, string $mode): array
    {
        return match (true) {
            $type === 'database' && $mode === 'provision' => ['engine' => 'mysql', 'name' => '', 'host' => '127.0.0.1', 'placement' => 'on_box', 'size' => 'small', 'vm_size' => ''],
            $type === 'database' => $this->defaultDatabaseAttachBindingForm(),
            // use_for_drivers: also wire cache/sessions/queue at this Redis in one
            // step (default on — it's why you attach Redis). Existing driver
            // bindings are preserved by the manager.
            $type === 'redis' => ['target_id' => '', 'use_for_drivers' => true],
            $type === 'queue' => ['driver' => 'database'],
            $type === 'cache' => $this->defaultCacheBindingForm(),
            $type === 'session' => $this->defaultSessionBindingForm(),
            $type === 'storage' => $this->defaultStorageBindingForm($mode),
            $type === 'logging' => $this->defaultLoggingBindingForm(),
            $type === 'mail' => $this->defaultMailBindingForm(),
            $type === 'broadcasting' => $this->defaultBroadcastingBindingForm(),
            $type === 'error_tracking' => $this->defaultErrorTrackingBindingForm(),
            $type === 'ai' => $this->defaultAiBindingForm(),
            $type === 'captcha' => $this->defaultCaptchaBindingForm(),
            $type === 'sms' => $this->defaultSmsBindingForm(),
            $type === 'search' => $this->defaultSearchBindingForm(),
            $type === 'payments' => $this->defaultPaymentsBindingForm(),
            $type === 'oauth' => $this->defaultOauthBindingForm(),
            default => [],
        };
    }

    /**
     * Default form for attaching an existing database. Prefills from the current
     * binding so re-opening "Configure" keeps the primary DB selection and any
     * advanced options the operator set previously. Secrets (read replica
     * password) are never echoed back — they stay in the encrypted injected_env.
     *
     * @return array<string, mixed>
     */
    private function defaultDatabaseAttachBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'database');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'target_id' => (string) ($existing?->target_type === 'server_database' ? ($existing->target_id ?? '') : ''),
            // Read replica
            'read_replica_type' => (string) ($cfg['read_replica_type'] ?? ''),
            'read_replica_id' => (string) ($cfg['read_replica_id'] ?? ''),
            'read_replica_host' => (string) ($cfg['read_replica_host'] ?? ''),
            'read_replica_port' => (string) ($cfg['read_replica_port'] ?? ''),
            'read_replica_username' => (string) ($cfg['read_replica_username'] ?? ''),
            'read_replica_password' => '',
            // Tuning options — prefilled from stored config, blank = framework default
            'db_prefix' => (string) ($cfg['db_prefix'] ?? ''),
            'db_charset' => (string) ($cfg['db_charset'] ?? ''),
            'db_collation' => (string) ($cfg['db_collation'] ?? ''),
            'db_strict' => (string) ($cfg['db_strict'] ?? ''),
            'db_engine' => (string) ($cfg['db_engine'] ?? ''),
            'db_socket' => (string) ($cfg['db_socket'] ?? ''),
            'db_schema' => (string) ($cfg['db_schema'] ?? ''),
            'db_sslmode' => (string) ($cfg['db_sslmode'] ?? ''),
            'db_timezone' => (string) ($cfg['db_timezone'] ?? ''),
        ];
    }

    /**
     * Seed the broadcasting form. Prefills kind/driver/tier from an existing
     * binding so re-opening keeps the current choice. With no managed apps in
     * the org yet, defaults the managed path to "provision new" so a first-time
     * operator lands on the create flow rather than an empty picker.
     *
     * @return array<string, mixed>
     */
    private function defaultBroadcastingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'broadcasting');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $hasApps = RealtimeApp::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereIn('status', [RealtimeApp::STATUS_ACTIVE, RealtimeApp::STATUS_PROVISIONING])
            ->exists();

        $defaultTier = (string) config('realtime.default_tier', 'starter');

        return [
            'kind' => (string) ($cfg['kind'] ?? 'managed'),
            // Managed: attach an existing app vs provision a new (billed) one.
            'provision' => ! $hasApps,
            'realtime_app_id' => (string) ($existing?->target_type === 'realtime_app' ? $existing->target_id : ''),
            'tier' => array_key_exists((string) ($cfg['tier'] ?? ''), (array) config('realtime.tiers', []))
                ? (string) $cfg['tier']
                : $defaultTier,
            'app_name' => '',
            'confirm_charge' => false,
            // BYO.
            'driver' => (string) ($cfg['driver'] ?? 'pusher'),
            'pusher_app_id' => '',
            'pusher_app_key' => '',
            'pusher_app_secret' => '',
            'pusher_host' => '',
            'pusher_port' => '',
            'pusher_scheme' => 'https',
            'pusher_cluster' => '',
            'reverb_app_id' => '',
            'reverb_app_key' => '',
            'reverb_app_secret' => '',
            'reverb_host' => '',
            'reverb_port' => '',
            'reverb_scheme' => 'https',
            'ably_key' => '',
        ];
    }

    /**
     * Default mail transport form. Prefills provider + from-address/name from an
     * existing mail binding's config so re-opening "Configure" keeps the current
     * values; secrets are never echoed back (re-enter, or reuse a saved
     * credential). When there's no binding yet, the from-address is seeded from
     * the app's current MAIL_FROM_ADDRESS so configuring adopts it.
     *
     * @return array<string, mixed>
     */
    private function defaultMailBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'mail');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $fromAddress = (string) ($cfg['from_address'] ?? '');
        $fromName = (string) ($cfg['from_name'] ?? '');

        if ($existing === null && ($fromAddress === '' || $fromName === '')) {
            $env = app(DeploymentSecretInventory::class)
                ->effectiveEnvironmentMapForSite($this->site);
            if ($fromAddress === '') {
                $fromAddress = trim((string) ($env['MAIL_FROM_ADDRESS'] ?? ''));
            }
            if ($fromName === '') {
                $fromName = trim((string) ($env['MAIL_FROM_NAME'] ?? ''));
            }
        }

        $provider = (string) ($cfg['provider'] ?? 'smtp');

        // Failover/round-robin: seed the leg rows from the saved chain (provider
        // slugs only — secrets are never echoed back, so each leg re-enters
        // creds or the chain re-saves them). Two empty legs when there's none.
        $legs = [];
        if (in_array($provider, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($cfg['legs'] ?? []) as $slug) {
                $legs[] = $this->emptyMailLeg((string) $slug);
            }
        }
        if (in_array($provider, ['failover', 'roundrobin'], true) && count($legs) < 2) {
            $legs = [$this->emptyMailLeg('smtp'), $this->emptyMailLeg('mailgun')];
        }

        return [
            'provider' => $provider,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            // Failover / round-robin legs (each a flat provider+creds row).
            'legs' => $legs,
            // SMTP
            'host' => '',
            'port' => '587',
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            // Mailgun
            'secret' => '',
            'domain' => '',
            'endpoint' => 'api.mailgun.net',
            // Postmark
            'token' => '',
            // SES
            'access_key_id' => '',
            'secret_access_key' => '',
            'region' => '',
            // Resend
            'key' => '',
            // Cloudflare (account_id + sending key); cf_domain drives the
            // guided "email on your domain" panel, defaulting to the primary.
            'account_id' => '',
            'cf_domain' => (string) ($this->site->primaryDomain()?->hostname ?? ''),
            // Saved-credential reuse + save-for-reuse.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Seed the session attach form. Prefills from an existing session binding's
     * stored config so re-opening "Configure" keeps the current values; a blank
     * field means "use the framework default" for that key.
     *
     * @return array<string, string>
     */
    private function defaultCacheBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'cache');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        $driver = (string) ($cfg['driver'] ?? '');
        $prefix = (string) ($cfg['prefix'] ?? '');

        // No binding yet → seed driver + prefix from the app's current env so
        // configuring the cache binding adopts the loose CACHE_STORE/CACHE_PREFIX
        // rather than resetting them or leaving a duplicate variable behind.
        if ($existing === null) {
            $env = app(DeploymentSecretInventory::class)
                ->effectiveEnvironmentMapForSite($this->site);
            if ($driver === '') {
                $driver = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));
            }
            if ($prefix === '') {
                $prefix = trim((string) ($env['CACHE_PREFIX'] ?? ''));
            }
        }

        if (! in_array($driver, ['database', 'redis', 'file', 'array'], true)) {
            $driver = 'database';
        }

        return [
            'driver' => $driver,
            'prefix' => $prefix,
        ];
    }

    private function defaultSessionBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'session');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            // Every field is optional — blank means "use the framework default",
            // which attach materializes into the injected config.
            'driver' => (string) ($cfg['driver'] ?? ''),
            'lifetime' => (string) ($cfg['lifetime'] ?? ''),
            'encrypt' => (string) ($cfg['encrypt'] ?? ''),
            'path' => (string) ($cfg['path'] ?? ''),
            'domain' => (string) ($cfg['domain'] ?? ''),
            'secure_cookie' => (string) ($cfg['secure_cookie'] ?? ''),
            'http_only' => (string) ($cfg['http_only'] ?? ''),
            'same_site' => (string) ($cfg['same_site'] ?? ''),
        ];
    }

    /**
     * Default object-storage form, seeded to the first provider and its first
     * region so the modal opens on a usable preset. In provision mode the
     * provider list is narrowed to those dply can actually create a bucket on.
     *
     * @return array<string, mixed>
     */
    private function defaultStorageBindingForm(string $mode = 'attach'): array
    {
        $providers = (array) config('object_storage.providers', []);
        if ($mode === 'provision') {
            $providers = array_filter($providers, fn ($p) => (bool) ($p['provision'] ?? false));
        }
        $provider = (string) (array_key_first($providers) ?? 'aws_s3');
        $regions = array_keys((array) ($providers[$provider]['regions'] ?? []));

        [$keySource, $cloudCredId] = $this->storageKeySourceDefault($provider, $mode);

        // First bucket on a site defaults to the primary `s3` disk (zero app
        // config); once that exists, an additional bucket must be named so it maps
        // to its own filesystem disk.
        $hasStorage = $this->site->bindings()->where('type', 'storage')->exists();

        return [
            'disk' => $hasStorage ? '' : 's3',
            'provider' => $provider,
            'region' => $regions[0] ?? '',
            'bucket' => '',
            'access_key_id' => '',
            'secret_access_key' => '',
            'url' => '',
            'endpoint' => '',
            // Saved-credential reuse: when set, the manager loads the keys from
            // an ObjectStorageCredential instead of the typed fields.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
            // Auto-create flow: 'api' has dply mint the S3 keys via the cloud
            // token in $provider_credential_id; 'manual' uses saved/typed keys.
            'key_source' => $keySource,
            'provider_credential_id' => $cloudCredId,
        ];
    }

    /**
     * Default key source for a storage provider+mode: API-managed creation only
     * in provision mode, only for api_managed providers, and only when the org
     * has a matching cloud token. Otherwise fall back to manual keys.
     *
     * @return array{0: string, 1: string} [key_source, provider_credential_id]
     */
    private function storageKeySourceDefault(string $provider, string $mode): array
    {
        if ($mode !== 'provision') {
            return ['manual', ''];
        }
        if (! (bool) config('object_storage.providers.'.$provider.'.api_managed', false)) {
            return ['manual', ''];
        }

        $creds = $this->cloudCredentialsForStorage($provider);
        if ($creds === []) {
            return ['manual', ''];
        }

        return ['api', (string) $creds[0]['id']];
    }

    /**
     * Default logging drain form. Prefills provider from an existing binding's
     * config so re-opening "Edit" keeps the current provider selected.
     *
     * @return array<string, mixed>
     */
    private function defaultLoggingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'logging');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'papertrail'),
            'host' => '',
            'port' => '',
            'source_token' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default error-tracking form. Prefills provider from an existing binding's
     * config so re-opening "Configure" keeps the current provider selected;
     * secrets (DSN/key) are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultErrorTrackingBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'error_tracking');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'sentry'),
            // Sentry
            'dsn' => '',
            'traces_sample_rate' => '',
            // Bugsnag
            'api_key' => '',
            // Flare
            'key' => '',
            // Saved-credential reuse + save-for-reuse.
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default AI/LLM key form. Prefills provider from an existing binding so
     * re-opening keeps the selection; the key is never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultAiBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'ai');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'openai'),
            'api_key' => '',
            'organization' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default CAPTCHA form. Prefills provider from an existing binding; keys are
     * never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultCaptchaBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'captcha');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'turnstile'),
            'site_key' => '',
            'secret_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default SMS / push form. Prefills provider from an existing binding;
     * secrets are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultSmsBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'sms');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'twilio'),
            // Twilio
            'sid' => '',
            'auth_token' => '',
            // Twilio + Vonage share a from number.
            'from' => '',
            // Vonage
            'key' => '',
            'secret' => '',
            // FCM
            'server_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default search (Scout) form. Prefills provider from an existing binding;
     * secrets are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultSearchBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'search');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'meilisearch'),
            // Algolia
            'app_id' => '',
            'secret' => '',
            // Meilisearch + Typesense share host.
            'host' => '',
            'key' => '',
            // Typesense
            'port' => '8108',
            'protocol' => 'http',
            'api_key' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default payments form. Prefills provider from an existing binding; secrets
     * are never echoed back.
     *
     * @return array<string, mixed>
     */
    private function defaultPaymentsBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'payments');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'stripe'),
            // Stripe
            'key' => '',
            'secret' => '',
            'currency' => '',
            // Paddle
            'api_key' => '',
            'client_side_token' => '',
            'sandbox' => '',
            // Shared
            'webhook_secret' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }

    /**
     * Default OAuth form. Prefills provider from an existing binding; the
     * redirect override is left blank so attach auto-derives it.
     *
     * @return array<string, mixed>
     */
    private function defaultOauthBindingForm(): array
    {
        $existing = $this->site->bindings->firstWhere('type', 'oauth');
        $cfg = is_array($existing?->config) ? $existing->config : [];

        return [
            'provider' => (string) ($cfg['provider'] ?? 'github'),
            'client_id' => '',
            'client_secret' => '',
            // Blank => auto-derive {site}/auth/{provider}/callback.
            'redirect' => '',
            'credential_id' => '',
            'save_credential' => false,
            'credential_name' => '',
        ];
    }
}
