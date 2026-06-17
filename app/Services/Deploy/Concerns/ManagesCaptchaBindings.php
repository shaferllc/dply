<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\CaptchaCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `captcha` binding type — reCAPTCHA / Cloudflare Turnstile /
 * hCaptcha. Pure config binding: it injects the provider's site key + secret at
 * deploy, plus a VITE_ mirror of the public site key so the browser bundle can
 * read it (the secret is server-only and never mirrored).
 */
trait ManagesCaptchaBindings
{
    /** Supported CAPTCHA providers. */
    public const CAPTCHA_PROVIDERS = ['recaptcha', 'turnstile', 'hcaptcha'];

    /**
     * Per-provider env var names: [site key env, secret env].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CAPTCHA_ENV = [
        'recaptcha' => ['RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY'],
        'turnstile' => ['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'],
        'hcaptcha' => ['HCAPTCHA_SITEKEY', 'HCAPTCHA_SECRET'],
    ];

    /**
     * @param  array<string, mixed> $params
     */
    private function attachCaptcha(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::CAPTCHA_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported CAPTCHA provider.'));
        }

        $creds = $this->resolveCaptchaCredentials($site, $provider, $params);
        if (($creds['site_key'] ?? '') === '' || ($creds['secret_key'] ?? '') === '') {
            throw new InvalidArgumentException(__('Both the site key and secret key are required.'));
        }

        $binding = $this->persist($site, 'captcha', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->captchaLabel($provider),
            'target_type' => 'captcha',
            'target_id' => null,
            'injected_env' => $this->captchaEnv($provider, $creds),
            'config' => ['provider' => $provider],
            'last_error' => null,
        ]);

        $this->maybeSaveCaptchaCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveCaptchaCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = CaptchaCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof CaptchaCredential) {
                throw new InvalidArgumentException(__('That saved CAPTCHA credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return array_filter([
            'site_key' => trim((string) ($params['site_key'] ?? '')),
            'secret_key' => trim((string) ($params['secret_key'] ?? '')),
        ], fn ($v) => $v !== '');
    }

    /**
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function captchaEnv(string $provider, array $creds): array
    {
        [$siteEnv, $secretEnv] = self::CAPTCHA_ENV[$provider];
        $siteKey = (string) ($creds['site_key'] ?? '');

        return [
            $siteEnv => $siteKey,
            $secretEnv => (string) ($creds['secret_key'] ?? ''),
            // Mirror the public site key for the Vite client bundle.
            'VITE_'.$siteEnv => $siteKey,
        ];
    }

    private function captchaLabel(string $provider): string
    {
        return match ($provider) {
            'recaptcha' => 'reCAPTCHA',
            'turnstile' => 'Cloudflare Turnstile',
            'hcaptcha' => 'hCaptcha',
            default => ucfirst($provider),
        };
    }

    /**
     * Every env key the captcha binding can own across providers, so switching
     * providers clears the previous provider's site/secret/VITE keys.
     *
     * @return list<string>
     */
    private function captchaOwnedEnvKeys(): array
    {
        $keys = [];
        foreach (self::CAPTCHA_ENV as [$siteEnv, $secretEnv]) {
            $keys[] = $siteEnv;
            $keys[] = $secretEnv;
            $keys[] = 'VITE_'.$siteEnv;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveCaptchaCredential(Site $site, string $provider, array $params, array $creds): void
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
            $name = $this->captchaLabel($provider).' '.__('keys');
        }

        CaptchaCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
