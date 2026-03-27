<?php

namespace App\Livewire\TwoFactor;

use App\Services\TwoFactorQrCodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use PragmaRX\Google2FA\Google2FA;

#[Layout('layouts.settings')]
class Page extends Component
{
    public string $code = '';

    public string $password = '';

    public string $disable_code = '';

    public function mount(): void
    {
        // No redirect: when no secret we show "Enable" button; when secret but not confirmed we show QR + confirm; when confirmed we show disable form.
    }

    /**
     * Start enabling 2FA: generate and store secret (then page re-renders with QR).
     */
    public function store(): void
    {
        $user = auth()->user();
        if ($user->hasTwoFactorEnabled()) {
            return;
        }
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function getIsManageModeProperty(): bool
    {
        $user = auth()->user();

        return ! empty($user->two_factor_confirmed_at);
    }

    public function getNeedsStartProperty(): bool
    {
        $user = auth()->user();

        return empty($user->two_factor_secret) && ! $user->hasTwoFactorEnabled();
    }

    public function getQrSvgProperty(): ?string
    {
        if ($this->isManageMode || $this->needsStart) {
            return null;
        }
        $user = auth()->user();
        $secret = $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;
        if (! $secret) {
            return null;
        }
        $google2fa = app(Google2FA::class);
        $otpauthUrl = $google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret
        );

        return app(TwoFactorQrCodeService::class)->svg($otpauthUrl);
    }

    public function confirm(): mixed
    {
        $this->validate(['code' => ['required', 'string', 'size:6']]);

        $user = auth()->user();
        $secret = decrypt($user->two_factor_secret);
        if (! app(Google2FA::class)->verifyKey($secret, $this->code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ]);
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = Collection::make($recoveryCodes)->map(fn ($code) => Hash::make($code))->values()->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($hashedCodes)),
        ])->save();

        Session::flash('recovery_codes', $recoveryCodes);
        Session::flash('status', 'two-factor-enabled');

        return $this->redirect(route('profile.edit'), navigate: true);
    }

    public function disable(): mixed
    {
        $this->validate([
            'password' => ['required', 'current_password'],
            'disable_code' => ['required', 'string'],
        ], [], ['disable_code' => __('code')]);

        $user = auth()->user();
        $code = $this->disable_code;

        if (strlen($code) === 6 && is_numeric($code)) {
            $secret = decrypt($user->two_factor_secret);
            if (! app(Google2FA::class)->verifyKey($secret, $code)) {
                throw ValidationException::withMessages([
                    'disable_code' => [__('The provided two factor authentication code was invalid.')],
                ]);
            }
        } else {
            $this->consumeRecoveryCode($user, $code);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Session::flash('status', 'two-factor-disabled');

        return $this->redirect(route('profile.edit'), navigate: true);
    }

    protected function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    protected function consumeRecoveryCode($user, string $code): void
    {
        $stored = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [];
        if (! is_array($stored)) {
            throw ValidationException::withMessages(['disable_code' => [__('The provided code was invalid.')]]);
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
        throw ValidationException::withMessages(['disable_code' => [__('The provided recovery code was invalid.')]]);
    }

    public function render(): View
    {
        return view('livewire.two-factor.page');
    }
}
