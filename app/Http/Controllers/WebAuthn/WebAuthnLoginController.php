<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Laragear\WebAuthn\Auth\WebAuthnUserProvider;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

class WebAuthnLoginController
{
    /**
     * Returns the challenge to assertion.
     */
    public function options(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate([
            'email' => ['required', 'string', 'email'],
        ]));
    }

    /**
     * Complete WebAuthn assertion and sign in (or stage two-factor challenge).
     */
    public function login(AssertedRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var WebAuthnUserProvider $provider */
        $provider = auth()->getProvider();

        $authenticatable = $provider->retrieveByCredentials($credentials);

        if (! $authenticatable instanceof User) {
            return response()->json(['message' => __('These credentials do not match our records.')], 422);
        }

        if (! $provider->validateCredentials($authenticatable, $credentials)) {
            return response()->json(['message' => __('These credentials do not match our records.')], 422);
        }

        /** @var User $user */
        $user = $authenticatable;

        $remember = $request->hasRemember();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.id', $user->getAuthIdentifier());
            $request->session()->put('login.remember', $remember);

            return response()->json([
                'two_factor' => true,
                'redirect' => route('two-factor.login', absolute: false),
            ]);
        }

        auth()->login($user, $remember);
        $request->session()->regenerate();

        $redirect = $user->hasVerifiedEmail()
            ? route('dashboard', absolute: false)
            : route('verification.notice', absolute: false);

        return response()->json([
            'logged_in' => true,
            'redirect' => $redirect,
        ]);
    }
}
