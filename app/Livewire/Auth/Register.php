<?php

namespace App\Livewire\Auth;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Forms\RegisterForm;
use App\Models\User;
use App\Services\Referrals\ReferralAttribution;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Component;

class Register extends Component
{
    public RegisterForm $form;

    public string $title = 'Create account';

    public function mount(): void
    {
        if (! auth()->check()) {
            return;
        }

        $this->redirect(
            auth()->user()->hasVerifiedEmail()
                ? route('dashboard')
                : route('verification.notice'),
            navigate: true
        );
    }

    public function submit(): mixed
    {
        $this->form->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $this->form->name,
            'email' => $this->form->email,
            'password' => Hash::make($this->form->password),
        ]);
        $organization = EnsureUserHasWorkspaceOrganization::run($user);

        ReferralAttribution::assignFromSession($user);

        event(new Registered($user));
        Auth::login($user);
        session()->regenerate();
        session(['current_organization_id' => $organization->id]);

        $target = $user->hasVerifiedEmail()
            ? route('dashboard')
            : route('verification.notice');

        return $this->redirect($target, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register', [
            'oauthProviders' => OAuthController::getEnabledProviders(),
        ])->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
