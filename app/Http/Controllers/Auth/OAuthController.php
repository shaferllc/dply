<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Referrals\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

class OAuthController extends Controller
{
    private const ALLOWED_PROVIDERS = ['github', 'bitbucket', 'gitlab'];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        if (Auth::check()) {
            Session::put('oauth_intent', 'link');
            if ($request->query('return') === 'security') {
                Session::put('oauth_return_route', 'profile.security');
            } else {
                Session::forget('oauth_return_route');
            }
        } else {
            Session::forget('oauth_intent');
            Session::forget('oauth_return_route');
        }

        $driver = Socialite::driver($provider);
        $scopes = config('services.'.$provider.'.scopes');
        if (is_array($scopes) && $scopes !== []) {
            $driver = $driver->scopes($scopes);
        }

        return $driver->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $intent = Session::pull('oauth_intent');

        try {
            $oauthUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            return $this->oauthFailureRedirect($intent, $e->getMessage());
        }

        if ($intent === 'link') {
            if (! Auth::check()) {
                return redirect()->route('login')
                    ->with('error', __('Your session expired. Please sign in and try linking again.'));
            }

            return $this->linkAccountToCurrentUser($provider, $oauthUser);
        }

        try {
            $user = $this->findOrCreateUser($provider, $oauthUser);
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->with('error', $e->getMessage());
        }

        Auth::login($user, true);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public static function getEnabledProviders(): array
    {
        $providers = [];
        if (config('services.github.client_id')) {
            $providers[] = ['id' => 'github', 'name' => 'GitHub'];
        }
        if (config('services.bitbucket.client_id')) {
            $providers[] = ['id' => 'bitbucket', 'name' => 'Bitbucket'];
        }
        if (config('services.gitlab.client_id')) {
            $providers[] = ['id' => 'gitlab', 'name' => 'GitLab'];
        }

        return $providers;
    }

    private function oauthFailureRedirect(?string $intent, string $message): RedirectResponse
    {
        if ($intent === 'link') {
            return redirect()->route(Session::pull('oauth_return_route', 'profile.source-control'))
                ->with('error', $message);
        }

        return redirect()->route('login')
            ->with('error', $message);
    }

    private function linkAccountToCurrentUser(string $provider, SocialiteUser $oauthUser): RedirectResponse
    {
        $returnRoute = Session::pull('oauth_return_route', 'profile.source-control');

        /** @var User $user */
        $user = Auth::user();
        $providerId = (string) $oauthUser->getId();

        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($existing && (string) $existing->user_id !== (string) $user->id) {
            return redirect()->route($returnRoute)
                ->with('error', __('This :provider account is already linked to another user.', ['provider' => ucfirst($provider)]));
        }

        $nickname = $oauthUser->getNickname() ?? $oauthUser->getName() ?? $providerId;
        $payload = [
            'access_token' => $oauthUser->token,
            'refresh_token' => $oauthUser->refreshToken ?? null,
            'nickname' => $nickname,
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            SocialAccount::create(array_merge([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $providerId,
                'label' => null,
            ], $payload));
        }

        return redirect()->route($returnRoute)
            ->with('success', __('Account linked.'));
    }

    private function validateProvider(string $provider): void
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            abort(404);
        }
    }

    private function findOrCreateUser(string $provider, SocialiteUser $oauthUser): User
    {
        $account = SocialAccount::where('provider', $provider)
            ->where('provider_id', (string) $oauthUser->getId())
            ->first();

        if ($account) {
            $account->update([
                'access_token' => $oauthUser->token,
                'refresh_token' => $oauthUser->refreshToken ?? null,
                'nickname' => $oauthUser->getNickname() ?? $oauthUser->getName(),
            ]);

            return $account->user;
        }

        $email = $oauthUser->getEmail();
        if (! $email) {
            throw new \RuntimeException(
                'Your '.$provider.' account did not provide an email address. Please use a different sign-in method or ensure your '.$provider.' profile has a public email.'
            );
        }

        $user = User::where('email', $email)->first();

        $nickname = $oauthUser->getNickname() ?? $oauthUser->getName() ?? explode('@', $email)[0];

        if ($user) {
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => (string) $oauthUser->getId(),
                'label' => null,
                'nickname' => $nickname,
                'access_token' => $oauthUser->token,
                'refresh_token' => $oauthUser->refreshToken ?? null,
            ]);

            return $user;
        }

        $user = User::create([
            'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? explode('@', $email)[0],
            'email' => $email,
            'password' => null,
            'email_verified_at' => now(),
        ]);

        ReferralAttribution::assignFromSession($user);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => (string) $oauthUser->getId(),
            'label' => null,
            'nickname' => $nickname,
            'access_token' => $oauthUser->token,
            'refresh_token' => $oauthUser->refreshToken ?? null,
        ]);

        return $user;
    }
}
