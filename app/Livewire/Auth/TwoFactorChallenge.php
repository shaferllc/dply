<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallenge extends Component
{
    public string $code = '';

    public string $title = 'Two-Factor Authentication';

    public function mount(): mixed
    {
        if (! session()->has('login.id')) {
            return $this->redirect(route('login'), navigate: true);
        }
        return null;
    }

    public function submit(): mixed
    {
        $this->validate([
            'code' => ['required', 'string'],
        ]);

        $userId = session()->get('login.id');
        $remember = session()->get('login.remember', false);

        $user = User::find($userId);
        if (! $user) {
            session()->forget(['login.id', 'login.remember']);

            return $this->redirect(route('login'), navigate: true);
        }

        $code = $this->code;

        if (strlen($code) === 6 && is_numeric($code)) {
            $secret = decrypt($user->two_factor_secret);
            if (! app(Google2FA::class)->verifyKey($secret, $code)) {
                throw ValidationException::withMessages([
                    'code' => [__('The provided two factor authentication code was invalid.')],
                ]);
            }
        } else {
            $this->consumeRecoveryCode($user, $code);
        }

        session()->forget(['login.id', 'login.remember']);
        Auth::login($user, $remember);
        session()->regenerate();

        return $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    protected function consumeRecoveryCode(User $user, string $code): void
    {
        $stored = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [];
        if (! is_array($stored)) {
            throw ValidationException::withMessages(['code' => [__('The provided code was invalid.')]]);
        }
        foreach ($stored as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($stored[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($stored))),
                ])->save();

                return;
            }
        }
        throw ValidationException::withMessages(['code' => [__('The provided recovery code was invalid.')]]);
    }

    public function render(): View
    {
        return view('livewire.auth.two-factor-challenge')
            ->layout('layouts.guest-livewire', ['title' => __($this->title)]);
    }
}
