<?php

namespace App\Livewire\Profile;

use App\Http\Controllers\SessionController;
use App\Livewire\Forms\ProfileBillingForm;
use App\Livewire\Forms\ProfileGeneralForm;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Edit extends Component
{
    public ProfileGeneralForm $profileForm;

    public ProfileBillingForm $billingForm;

    public bool $verificationLinkSent = false;

    public function mount(): void
    {
        $user = $this->user();
        $this->profileForm->fill([
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => $user->country_code ?? '',
            'locale' => $user->locale ?? config('app.locale'),
            'timezone' => $user->timezone ?? config('app.timezone'),
        ]);
        $this->billingForm->fill([
            'invoice_email' => $user->invoice_email ?? '',
            'vat_number' => $user->vat_number ?? '',
            'billing_currency' => $user->billing_currency ?? '',
            'billing_details' => $user->billing_details ?? '',
        ]);
    }

    protected function user()
    {
        return auth()->user();
    }

    public function getSessionsProperty(): array
    {
        $user = $this->user();
        if (! $user) {
            return [];
        }

        return SessionController::listSessionsForUser($user->id, session()->getId());
    }

    public function getGravatarUrlProperty(): string
    {
        $email = strtolower(trim($this->profileForm->email));

        return 'https://www.gravatar.com/avatar/'.md5($email).'?s=128&d=mp';
    }

    public function getTimezonesProperty(): array
    {
        return collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL))
            ->sort()
            ->values()
            ->all();
    }

    public function updateProfile(): void
    {
        $user = $this->user();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['required', 'string', Rule::in(array_keys(config('profile_options.locales')))],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL))],
        ];
        $rules['country_code'] = $this->profileForm->country_code === ''
            ? ['nullable']
            : ['string', 'size:2', Rule::in(array_keys(config('profile_options.countries')))];

        $this->profileForm->validate($rules);

        $user->fill([
            'name' => $this->profileForm->name,
            'email' => $this->profileForm->email,
            'country_code' => $this->profileForm->country_code === '' ? null : $this->profileForm->country_code,
            'locale' => $this->profileForm->locale,
            'timezone' => $this->profileForm->timezone,
        ]);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        $this->dispatch('profile-updated');
    }

    public function updateBilling(): void
    {
        $rules = [
            'invoice_email' => ['nullable', 'string', 'email', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'billing_details' => ['nullable', 'string', 'max:5000'],
        ];
        $rules['billing_currency'] = $this->billingForm->billing_currency === ''
            ? ['nullable']
            : ['string', 'size:3', Rule::in(array_keys(config('profile_options.currencies')))];

        $this->billingForm->validate($rules);

        $this->user()->update([
            'invoice_email' => $this->billingForm->invoice_email !== '' ? $this->billingForm->invoice_email : null,
            'vat_number' => $this->billingForm->vat_number !== '' ? $this->billingForm->vat_number : null,
            'billing_currency' => $this->billingForm->billing_currency === '' ? null : $this->billingForm->billing_currency,
            'billing_details' => $this->billingForm->billing_details !== '' ? $this->billingForm->billing_details : null,
        ]);

        $this->dispatch('billing-updated');
    }

    public function revokeSession(string $sessionId): void
    {
        $userId = $this->user()?->id;
        if (! $userId) {
            return;
        }
        if ($sessionId === session()->getId()) {
            $this->addError('session', __('You cannot revoke your current session.'));

            return;
        }

        $table = config('session.table', 'sessions');
        $deleted = DB::table($table)
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->delete();

        if ($deleted) {
            $this->dispatch('session-revoked');
        } else {
            $this->addError('session', __('Session not found or already revoked.'));
        }
    }

    public function revokeOtherSessions(): void
    {
        $userId = $this->user()?->id;
        if (! $userId) {
            return;
        }

        $table = config('session.table', 'sessions');
        DB::table($table)
            ->where('user_id', $userId)
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->dispatch('sessions-revoked');
    }

    public function sendVerificationEmail(): void
    {
        $user = $this->user();
        if ($user->hasVerifiedEmail()) {
            return;
        }
        $user->sendEmailVerificationNotification();
        $this->verificationLinkSent = true;
    }

    public function render(): View
    {
        return view('livewire.profile.edit');
    }
}
