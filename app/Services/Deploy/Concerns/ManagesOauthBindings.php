<?php

declare(strict_types=1);

namespace App\Services\Deploy\Concerns;

use App\Models\OauthCredential;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Attach the `oauth` binding type — a Socialite OAuth provider (GitHub, Google,
 * Facebook, GitLab, LinkedIn). It injects {PROVIDER}_CLIENT_ID /
 * {PROVIDER}_CLIENT_SECRET / {PROVIDER}_REDIRECT_URI at deploy, auto-filling the
 * redirect URI from the site's primary hostname — the usual footgun — unless
 * the operator supplies an explicit override.
 *
 * v1 stores one provider per binding (updateOrCreate keyed on site+type);
 * multi-provider in a single binding is a follow-up.
 */
trait ManagesOauthBindings
{
    use ResolvesSitePublicUrl;

    /** Supported Socialite providers. */
    public const OAUTH_PROVIDERS = ['github', 'google', 'facebook', 'gitlab', 'linkedin'];

    /**
     * @param  array<string, mixed> $params
     */
    private function attachOauth(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, self::OAUTH_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported OAuth provider.'));
        }

        $creds = $this->resolveOauthCredentials($site, $provider, $params);
        if (($creds['client_id'] ?? '') === '' || ($creds['client_secret'] ?? '') === '') {
            throw new InvalidArgumentException(__('Client ID and client secret are required.'));
        }

        $redirect = $this->oauthRedirectUri($site, $provider, (string) ($creds['redirect'] ?? $params['redirect'] ?? ''));
        $creds['redirect'] = $redirect;

        $binding = $this->persist($site, 'oauth', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->oauthLabel($provider),
            'target_type' => 'oauth',
            'target_id' => null,
            'injected_env' => $this->oauthEnv($provider, $creds),
            'config' => ['provider' => $provider, 'redirect' => $redirect],
            'last_error' => null,
        ]);

        $this->maybeSaveOauthCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveOauthCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = OauthCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof OauthCredential) {
                throw new InvalidArgumentException(__('That saved OAuth credential is no longer available.'));
            }

            return ($cred->credentials );
        }

        return array_filter([
            'client_id' => trim((string) ($params['client_id'] ?? '')),
            'client_secret' => trim((string) ($params['client_secret'] ?? '')),
            'redirect' => trim((string) ($params['redirect'] ?? '')),
        ], fn ($v) => $v !== '');
    }

    /**
     * Resolve the redirect URI: an explicit override wins; otherwise derive
     * {base}/auth/{provider}/callback from the site's primary hostname. Throws
     * when neither is available so an OAuth app isn't wired with a blank callback.
     */
    private function oauthRedirectUri(Site $site, string $provider, string $override): string
    {
        $override = trim($override);
        if ($override !== '') {
            return $override;
        }

        $base = $this->siteBaseUrl($site);
        if ($base === null) {
            throw new InvalidArgumentException(__('Add a primary domain to this site, or enter a redirect URL — the OAuth callback URL cannot be derived yet.'));
        }

        return $base.'/auth/'.$provider.'/callback';
    }

    /**
     * @param  array<string, mixed> $creds
     * @return array<string, string>
     */
    private function oauthEnv(string $provider, array $creds): array
    {
        $prefix = strtoupper($provider);

        return array_filter([
            $prefix.'_CLIENT_ID' => (string) ($creds['client_id'] ?? ''),
            $prefix.'_CLIENT_SECRET' => (string) ($creds['client_secret'] ?? ''),
            $prefix.'_REDIRECT_URI' => (string) ($creds['redirect'] ?? ''),
        ], fn ($v) => $v !== '');
    }

    private function oauthLabel(string $provider): string
    {
        return match ($provider) {
            'github' => 'GitHub',
            'google' => 'Google',
            'facebook' => 'Facebook',
            'gitlab' => 'GitLab',
            'linkedin' => 'LinkedIn',
            default => ucfirst($provider),
        };
    }

    /**
     * Every env key the oauth binding can own across providers, so switching
     * providers clears the previous provider's client vars.
     *
     * @return list<string>
     */
    private function oauthOwnedEnvKeys(): array
    {
        $keys = [];
        foreach (self::OAUTH_PROVIDERS as $provider) {
            $prefix = strtoupper($provider);
            $keys[] = $prefix.'_CLIENT_ID';
            $keys[] = $prefix.'_CLIENT_SECRET';
            $keys[] = $prefix.'_REDIRECT_URI';
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $creds
     */
    private function maybeSaveOauthCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if (($creds['client_id'] ?? '') === '') {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $name = $this->oauthLabel($provider).' '.__('OAuth app');
        }

        // Persist only the durable client id/secret — the redirect is per-site.
        OauthCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => [
                'client_id' => (string) ($creds['client_id'] ?? ''),
                'client_secret' => (string) ($creds['client_secret'] ?? ''),
            ],
        ]);
    }
}
