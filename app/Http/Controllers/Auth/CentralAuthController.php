<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dply\Core\Auth\CentralOAuthClient;
use Dply\Core\Auth\CentralOAuthException;
use Dply\Core\Auth\OAuthPkce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CentralAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! config('dply_auth.enabled')) {
            abort(404);
        }

        $verifier = OAuthPkce::generateCodeVerifier(64);
        $challenge = OAuthPkce::codeChallengeS256($verifier);
        $state = Str::random(40);

        $request->session()->put('dply_auth_oauth', [
            'verifier' => $verifier,
            'state' => $state,
        ]);

        $url = app(CentralOAuthClient::class)->authorizationUrl($state, $challenge);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! config('dply_auth.enabled')) {
            abort(404);
        }

        $payload = $request->session()->pull('dply_auth_oauth');
        if (! is_array($payload)
            || empty($payload['verifier'])
            || empty($payload['state'])
            || ! is_string($request->query('state'))
            || ! hash_equals($payload['state'], $request->query('state'))) {
            return redirect()->route('login')
                ->with('error', __('Your sign-in session expired. Please try again.'));
        }

        if ($request->query('error')) {
            $message = (string) ($request->query('error_description') ?: $request->query('error'));

            return redirect()->route('login')->with('error', $message);
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('login')
                ->with('error', __('Missing authorization code. Please try again.'));
        }

        try {
            $client = app(CentralOAuthClient::class);
            $tokens = $client->exchangeAuthorizationCode($code, $payload['verifier']);
            $profile = $client->fetchUserProfile($tokens['access_token']);
        } catch (CentralOAuthException $e) {
            return redirect()->route('login')->with('error', $e->getMessage());
        }

        $centralId = (string) $profile['id'];

        $user = User::query()->where('dply_auth_id', $centralId)->first()
            ?? User::query()->where('email', $profile['email'])->first();

        if ($user) {
            $updates = [];
            if ($user->dply_auth_id === null || (string) $user->dply_auth_id !== $centralId) {
                $updates['dply_auth_id'] = $centralId;
            }
            if ($user->name !== $profile['name']) {
                $updates['name'] = $profile['name'];
            }
            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }
        } else {
            $user = User::query()->create([
                'name' => $profile['name'],
                'email' => $profile['email'],
                'password' => Hash::make(Str::password(64)),
                'dply_auth_id' => $centralId,
                'email_verified_at' => $profile['email_verified_at'] !== null ? now() : null,
            ]);
        }

        if ($profile['email_verified_at'] !== null && ! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $organization = EnsureUserHasWorkspaceOrganization::run($user);

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->put('current_organization_id', $organization->id);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
