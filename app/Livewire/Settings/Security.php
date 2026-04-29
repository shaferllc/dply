<?php

namespace App\Livewire\Settings;

use App\Actions\Auth\UnlinkSocialAccount;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\InteractsWithUnsavedChangesBar;
use App\Models\SocialAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Security extends Component
{
    use ConfirmsActionWithModal;
    use InteractsWithUnsavedChangesBar;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Friendly names for listed passkeys, keyed by credential id.
     *
     * @var array<string, string>
     */
    public array $passkeyAliases = [];

    public function mount(): void
    {
        $this->loadPasskeyAliases();
    }

    protected function user()
    {
        return auth()->user();
    }

    protected function loadPasskeyAliases(): void
    {
        $this->passkeyAliases = $this->user()
            ->webAuthnCredentials()
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(fn ($credential) => [$credential->getKey() => $credential->alias ?? ''])
            ->all();
    }

    public function savePasskeyAlias(string $credentialId): void
    {
        $field = 'passkeyAliases.'.$credentialId;

        $this->validate([
            $field => ['nullable', 'string', 'max:255'],
        ]);

        $raw = $this->passkeyAliases[$credentialId] ?? '';
        $trimmed = is_string($raw) ? trim($raw) : '';

        $credential = $this->user()->webAuthnCredentials()->whereKey($credentialId)->firstOrFail();
        $credential->forceFill([
            'alias' => $trimmed === '' ? null : $trimmed,
        ])->save();
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [], [
            'current_password' => __('current password'),
            'password' => __('new password'),
        ]);

        $this->user()->update([
            'password' => Hash::make($this->password),
        ]);

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->dispatch('password-updated');
    }

    public function discardPasswordUnsaved(): void
    {
        $this->reset(['current_password', 'password', 'password_confirmation']);
    }

    public function removePasskey(string $credentialId): void
    {
        $user = $this->user();

        $otherPasskeys = $user->webAuthnCredentials()->whereKeyNot($credentialId)->whereEnabled()->count();
        if ($user->password === null && $user->socialAccounts()->count() === 0 && $otherPasskeys === 0) {
            $this->addError('passkey', __('Add a password or OAuth sign-in before removing your only passkey.'));

            return;
        }

        $credential = $user->webAuthnCredentials()->whereKey($credentialId)->firstOrFail();
        $credential->delete();

        unset($this->passkeyAliases[$credentialId]);
    }

    public function unlinkOAuthAccount(int|string $accountId): void
    {
        $user = $this->user();

        SocialAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($accountId)
            ->firstOrFail();

        if (! UnlinkSocialAccount::allowed($user)) {
            $this->addError('unlink', UnlinkSocialAccount::denyMessage());

            return;
        }

        SocialAccount::query()
            ->where('user_id', $user->id)
            ->whereKey($accountId)
            ->delete();
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.settings.security', [
            'oauthProviders' => OAuthController::getEnabledProviders(),
            'passkeys' => $user->webAuthnCredentials()->orderByDesc('created_at')->get(),
            'socialAccounts' => $user->socialAccounts()->orderBy('provider')->orderBy('id')->get(),
        ]);
    }
}
