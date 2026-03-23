<?php

namespace App\Livewire\Auth;

use App\Http\Controllers\Auth\OAuthController;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public string $title = 'Log in';

    public function mount(): void
    {
        if (auth()->check()) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    public function submit(): mixed
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::transliterate(Str::lower($this->email).'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        $user = User::where('email', $this->email)->first();
        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactorEnabled()) {
            session()->put('login.id', $user->id);
            session()->put('login.remember', $this->remember);

            return $this->redirect(route('two-factor.login'), navigate: true);
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        return $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.login', [
            'oauthProviders' => OAuthController::getEnabledProviders(),
        ])->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
