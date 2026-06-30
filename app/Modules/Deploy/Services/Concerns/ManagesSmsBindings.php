<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `sms` binding type — SMS / push notification providers (Twilio,
 * Vonage, Firebase Cloud Messaging). Pure config binding: it injects the
 * provider's connection env at deploy from a saved {@see SmsCredential} or the
 * typed form, for use as a Laravel notification channel.
 */
trait ManagesSmsBindings
{
    /** Supported SMS / push providers. */
    public const SMS_PROVIDERS = ['twilio', 'vonage', 'fcm'];

    /**
     * @param  array<string, mixed> $params
     */
    private function attachSms(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::SMS_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported SMS provider.'));
        }

        $creds = $this->resolveSmsCredentials($site, $provider, $params);
        $this->validateSmsCredentials($provider, $creds);

        // Multi-instance: keyed by provider (Twilio/Vonage/FCM own distinct
        // keys), so several coexist. Editing updates by id.
        $binding = $this->persistInstanceBinding($site, 'sms', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->smsLabel($provider),
            'target_type' => 'sms',
            'target_id' => null,
            'injected_env' => $this->smsEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ], false, trim((string) ($params['binding_id'] ?? '')));

        $this->maybeSaveSmsCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveSmsCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = SmsCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof SmsCredential) {
                throw new InvalidArgumentException(__('That saved SMS credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return match ($provider) {
            'twilio' => array_filter([
                'sid' => trim((string) ($params['sid'] ?? '')),
                'auth_token' => trim((string) ($params['auth_token'] ?? '')),
                'from' => trim((string) ($params['from'] ?? '')),
            ], fn ($v) => $v !== ''),
            'vonage' => array_filter([
                'key' => trim((string) ($params['key'] ?? '')),
                'secret' => trim((string) ($params['secret'] ?? '')),
                'from' => trim((string) ($params['from'] ?? '')),
            ], fn ($v) => $v !== ''),
            'fcm' => array_filter([
                'server_key' => trim((string) ($params['server_key'] ?? '')),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function validateSmsCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'twilio' => ($creds['sid'] ?? '') === '' || ($creds['auth_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Twilio Account SID and Auth Token are required.'))
                : null,
            'vonage' => ($creds['key'] ?? '') === '' || ($creds['secret'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Vonage API key and secret are required.'))
                : null,
            'fcm' => ($creds['server_key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('FCM server key is required.'))
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function smsEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'twilio' => array_filter([
                'TWILIO_SID' => (string) ($creds['sid'] ?? ''),
                'TWILIO_AUTH_TOKEN' => (string) ($creds['auth_token'] ?? ''),
                'TWILIO_FROM' => (string) ($creds['from'] ?? ''),
            ], fn ($v) => $v !== ''),
            'vonage' => array_filter([
                'VONAGE_KEY' => (string) ($creds['key'] ?? ''),
                'VONAGE_SECRET' => (string) ($creds['secret'] ?? ''),
                'VONAGE_SMS_FROM' => (string) ($creds['from'] ?? ''),
            ], fn ($v) => $v !== ''),
            'fcm' => array_filter([
                'FCM_SERVER_KEY' => (string) ($creds['server_key'] ?? ''),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    private function smsLabel(string $provider): string
    {
        return match ($provider) {
            'twilio' => 'Twilio',
            'vonage' => 'Vonage',
            'fcm' => 'Firebase Cloud Messaging',
            default => ucfirst($provider),
        };
    }

    /**
     * Every env key the sms binding can own across providers, so switching
     * providers clears the previous provider's connection vars.
     *
     * @return list<string>
     */
    private function smsOwnedEnvKeys(): array
    {
        return [
            'TWILIO_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_FROM',
            'VONAGE_KEY', 'VONAGE_SECRET', 'VONAGE_SMS_FROM',
            'FCM_SERVER_KEY',
        ];
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveSmsCredential(Site $site, string $provider, array $params, array $creds): void
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
            $name = $this->smsLabel($provider).' '.__('credentials');
        }

        SmsCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
