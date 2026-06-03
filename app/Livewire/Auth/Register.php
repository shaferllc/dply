<?php

namespace App\Livewire\Auth;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Http\Controllers\Auth\OAuthController;
use App\Livewire\Forms\RegisterForm;
use App\Models\BetaInvitation;
use App\Models\User;
use App\Services\Referrals\ReferralAttribution;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

class Register extends Component
{
    public RegisterForm $form;

    public string $title = 'Create account';

    /**
     * Beta invite token from the emailed link (?invite=…). A valid token lets
     * this email register while public signups are closed and flags the new org
     * as a beta participant.
     */
    #[Url(as: 'invite')]
    public ?string $invite = null;

    /**
     * Email is locked to the invited address when redeeming — preserves the
     * 1:1 invite→person→free-box attribution.
     */
    public bool $emailLocked = false;

    public function mount(): void
    {
        $invitation = $this->resolveInvitation();

        // A valid, unredeemed invite bypasses the closed-signups gate. Without
        // one, closed signups send the visitor to the waitlist as before.
        if ($invitation === null && ! Feature::active('global.signups_open')) {
            // A token that's present but no longer valid is a warm lead — funnel
            // them to the waitlist with a friendly note rather than a dead end.
            if (filled($this->invite)) {
                session()->flash('status', __('That beta invite is no longer valid. Join the waitlist and we’ll send a fresh one.'));
            }

            $this->redirect(route('coming-soon'), navigate: true);

            return;
        }

        if ($invitation !== null) {
            // New-signups-only: an invited address that already has an account
            // bounces to login rather than silently granting beta.
            if (User::where('email', $invitation->email)->exists()) {
                session()->flash('status', __('You already have an account — please log in.'));
                $this->redirect(route('login'), navigate: true);

                return;
            }

            // Lock the form email to the invited address.
            $this->form->email = $invitation->email;
            $this->emailLocked = true;
        }

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

    /**
     * The redeemable invite for the current token, or null when absent/invalid.
     */
    private function resolveInvitation(): ?BetaInvitation
    {
        if (blank($this->invite)) {
            return null;
        }

        $invitation = BetaInvitation::where('token', $this->invite)->first();

        return $invitation !== null && $invitation->isRedeemable() ? $invitation : null;
    }

    public function submit(): mixed
    {
        $invitation = $this->resolveInvitation();

        // Re-pin the email to the invite server-side — never trust a client that
        // edited the locked field.
        if ($invitation !== null) {
            $this->form->email = $invitation->email;
        } elseif (! Feature::active('global.signups_open')) {
            // Token went stale between mount and submit (expired/redeemed/revoked)
            // and signups are still closed — don't mint an account.
            session()->flash('status', __('That beta invite is no longer valid. Join the waitlist and we’ll send a fresh one.'));

            return $this->redirect(route('coming-soon'), navigate: true);
        }

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

        // Redeem the invite: flag the new org beta + apply the beta feature
        // bundle (see BetaInvitation::redeem).
        $invitation?->redeem($user, $organization);

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
