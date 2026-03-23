<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

class OAuthController extends Controller
{
    private const ALLOWED_PROVIDERS = ['github', 'bitbucket', 'gitlab'];

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        try {
            $oauthUser = Socialite::driver($provider)->user();
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
            return $account->user;
        }

        $email = $oauthUser->getEmail();
        if (! $email) {
            throw new \RuntimeException(
                'Your '.$provider.' account did not provide an email address. Please use a different sign-in method or ensure your '.$provider.' profile has a public email.'
            );
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => (string) $oauthUser->getId(),
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

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => (string) $oauthUser->getId(),
            'access_token' => $oauthUser->token,
            'refresh_token' => $oauthUser->refreshToken ?? null,
        ]);

        return $user;
    }
}
