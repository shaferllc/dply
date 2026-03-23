<?php

namespace App\Livewire\Invitations;

use App\Models\OrganizationInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Accept extends Component
{
    public string $token = '';

    public ?OrganizationInvitation $invitation = null;

    public ?string $error = null;

    public bool $resolved = false;

    public function mount(string $token): mixed
    {
        $this->token = $token;
        $this->invitation = OrganizationInvitation::where('token', $token)->with(['organization', 'inviter'])->first();

        if (! $this->invitation) {
            $this->error = 'Invitation not found.';
            $this->resolved = true;

            return $this->redirect(route('organizations.index'), navigate: true);
        }

        if ($this->invitation->isExpired()) {
            $this->invitation->delete();
            $this->error = 'This invitation has expired.';
            $this->resolved = true;
            Session::flash('error', $this->error);

            return $this->redirect(route('organizations.index'), navigate: true);
        }

        $user = auth()->user();
        if (strtolower($user->email) !== strtolower($this->invitation->email)) {
            $this->error = 'Please sign in with the email address that was invited ('.$this->invitation->email.').';
            $this->resolved = true;
            Session::flash('error', $this->error);

            return $this->redirect(route('organizations.index'), navigate: true);
        }

        if ($this->invitation->organization->hasMember($user)) {
            $this->invitation->delete();
            Session::put('current_organization_id', $this->invitation->organization_id);
            Session::flash('success', 'You are already a member of this organization.');

            return $this->redirect(route('organizations.show', $this->invitation->organization), navigate: true);
        }

        return null;
    }

    public function accept(): mixed
    {
        if (! $this->invitation || $this->invitation->isExpired()) {
            return $this->redirect(route('organizations.index'), navigate: true);
        }

        $user = auth()->user();
        $this->invitation->organization->users()->attach($user->id, ['role' => $this->invitation->role]);
        $org = $this->invitation->organization;
        $this->invitation->delete();
        Session::put('current_organization_id', $org->id);
        Session::flash('success', 'You have joined '.$org->name.'.');

        return $this->redirect(route('organizations.show', $org), navigate: true);
    }

    public function decline(): mixed
    {
        if ($this->invitation) {
            $this->invitation->delete();
        }
        Session::flash('success', 'Invitation declined.');

        return $this->redirect(route('organizations.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.invitations.accept');
    }
}
