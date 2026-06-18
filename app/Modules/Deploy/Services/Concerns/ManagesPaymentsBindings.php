<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\PaymentCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `payments` binding type — Stripe or Paddle, via Laravel Cashier.
 * It injects the provider's keys at deploy plus a VITE_ mirror of the public
 * client key, and computes the webhook endpoint URL from the site's primary
 * hostname so the operator can register it (Cashier's default route).
 *
 * Live API registration of the webhook against Stripe/Paddle is a follow-up; v1
 * surfaces the URL and stores it on the binding config.
 */
trait ManagesPaymentsBindings
{
    use ResolvesSitePublicUrl;

    /** Supported payment providers. */
    public const PAYMENT_PROVIDERS = ['stripe', 'paddle'];

    /** Cashier's default webhook route path per provider. */
    public const PAYMENT_WEBHOOK_PATHS = [
        'stripe' => '/stripe/webhook',
        'paddle' => '/paddle/webhook',
    ];

    /**
     * @param  array<string, mixed> $params
     */
    private function attachPayments(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::PAYMENT_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported payments provider.'));
        }

        $creds = $this->resolvePaymentCredentials($site, $provider, $params);
        $this->validatePaymentCredentials($provider, $creds);

        $webhookUrl = $this->paymentsWebhookUrl($site, $provider);

        $binding = $this->persist($site, 'payments', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->paymentsLabel($provider),
            'target_type' => 'payments',
            'target_id' => null,
            'injected_env' => $this->paymentsEnv($provider, $creds),
            'config' => array_filter([
                'provider' => $provider,
                'webhook_url' => $webhookUrl,
            ], fn ($v) => $v !== null),
            'last_error' => null,
        ]);

        $this->maybeSavePaymentCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolvePaymentCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = PaymentCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof PaymentCredential) {
                throw new InvalidArgumentException(__('That saved payments credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return match ($provider) {
            'stripe' => array_filter([
                'key' => trim((string) ($params['key'] ?? '')),
                'secret' => trim((string) ($params['secret'] ?? '')),
                'webhook_secret' => trim((string) ($params['webhook_secret'] ?? '')),
                'currency' => trim((string) ($params['currency'] ?? '')),
            ], fn ($v) => $v !== ''),
            'paddle' => array_filter([
                'api_key' => trim((string) ($params['api_key'] ?? '')),
                'client_side_token' => trim((string) ($params['client_side_token'] ?? '')),
                'webhook_secret' => trim((string) ($params['webhook_secret'] ?? '')),
                'sandbox' => trim((string) ($params['sandbox'] ?? '')),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /** @param  array<string, mixed> $creds */
    private function validatePaymentCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'stripe' => ($creds['key'] ?? '') === '' || ($creds['secret'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Stripe publishable key and secret key are required.'))
                : null,
            'paddle' => ($creds['api_key'] ?? '') === '' || ($creds['client_side_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Paddle API key and client-side token are required.'))
                : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function paymentsEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'stripe' => array_filter([
                'STRIPE_KEY' => (string) ($creds['key'] ?? ''),
                'STRIPE_SECRET' => (string) ($creds['secret'] ?? ''),
                'STRIPE_WEBHOOK_SECRET' => (string) ($creds['webhook_secret'] ?? ''),
                'CASHIER_CURRENCY' => (string) ($creds['currency'] ?? ''),
                // Publishable key is client-safe → mirror for the Vite bundle.
                'VITE_STRIPE_KEY' => (string) ($creds['key'] ?? ''),
            ], fn ($v) => $v !== ''),
            'paddle' => array_filter([
                'PADDLE_API_KEY' => (string) ($creds['api_key'] ?? ''),
                'PADDLE_CLIENT_SIDE_TOKEN' => (string) ($creds['client_side_token'] ?? ''),
                'PADDLE_WEBHOOK_SECRET' => (string) ($creds['webhook_secret'] ?? ''),
                'PADDLE_SANDBOX' => (string) ($creds['sandbox'] ?? ''),
                'VITE_PADDLE_CLIENT_SIDE_TOKEN' => (string) ($creds['client_side_token'] ?? ''),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /**
     * The Cashier webhook endpoint URL for this site's primary hostname, or null
     * when the site has no resolvable public URL yet.
     */
    private function paymentsWebhookUrl(Site $site, string $provider): ?string
    {
        $base = $this->siteBaseUrl($site);
        if ($base === null) {
            return null;
        }

        return $base.(self::PAYMENT_WEBHOOK_PATHS[$provider] ?? '/webhook');
    }

    private function paymentsLabel(string $provider): string
    {
        return match ($provider) {
            'stripe' => 'Stripe',
            'paddle' => 'Paddle',
            default => ucfirst($provider),
        };
    }

    /**
     * @return list<string>
     */
    private function paymentsOwnedEnvKeys(): array
    {
        return [
            'STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'CASHIER_CURRENCY', 'VITE_STRIPE_KEY',
            'PADDLE_API_KEY', 'PADDLE_CLIENT_SIDE_TOKEN', 'PADDLE_WEBHOOK_SECRET', 'PADDLE_SANDBOX', 'VITE_PADDLE_CLIENT_SIDE_TOKEN',
        ];
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSavePaymentCredential(Site $site, string $provider, array $params, array $creds): void
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
            $name = $this->paymentsLabel($provider).' '.__('keys');
        }

        PaymentCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }
}
