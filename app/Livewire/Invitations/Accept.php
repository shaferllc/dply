<?php

namespace App\Livewire\Invitations;

use App\Models\OrganizationInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

/**
 * The invitation landing page. Deliberately **public**: an invited person
 * usually has no account yet, so bouncing them to a bare login screen loses
 * every bit of context about who invited them and why. Guests see the
 * invitation itself plus a way to register or sign in; the join only happens
 * once they're authenticated as the invited address.
 */
class Accept extends Component
{
    public string $token = '';

    public ?OrganizationInvitation $invitation = null;

    public ?string $error = null;

    public bool $resolved = false;

    /**
     * What the page should render:
     *  - invalid  — no such token
     *  - expired  — token found but past its expiry
     *  - guest    — valid invitation, nobody signed in
     *  - mismatch — signed in as somebody other than the invited address
     *  - ready    — signed in as the invitee; accept/decline
     */
    public string $state = 'invalid';

    public function mount(string $token): mixed
    {
        $this->token = $token;
        $this->invitation = OrganizationInvitation::where('token', $token)
            ->with(['organization', 'inviter', 'team'])
            ->first();

        if (! $this->invitation) {
            $this->state = 'invalid';
            $this->error = 'This invitation link is not valid. Ask whoever invited you to send a new one.';
            $this->resolved = true;

            return null;
        }

        if ($this->invitation->isExpired()) {
            $this->invitation->delete();
            $this->invitation = null;
            $this->state = 'expired';
            $this->error = 'This invitation has expired. Ask whoever invited you to send a new one.';
            $this->resolved = true;

            return null;
        }

        // Guest: show the invitation and let them register or sign in. The
        // intended URL brings them back here once authenticated.
        if (! auth()->check()) {
            Session::put('url.intended', route('invitations.accept', ['token' => $token]));
            $this->state = 'guest';

            return null;
        }

        $user = auth()->user();
        if (strtolower($user->email) !== strtolower($this->invitation->email)) {
            $this->state = 'mismatch';
            $this->error = 'This invitation was sent to '.$this->invitation->email.'. Sign in with that address to accept it.';

            return null;
        }

        if ($this->invitation->organization->hasMember($user)) {
            // Already in the org, but a team invite still has something to
            // grant — honour the team half rather than silently discarding it.
            $team = $this->invitation->team_id
                ? $this->invitation->organization->teams()->find($this->invitation->team_id)
                : null;
            $joinedTeam = false;
            if ($team && ! $team->users()->where('user_id', $user->id)->exists()) {
                $team->users()->attach($user->id, ['role' => 'member']);
                $joinedTeam = true;
            }

            $this->invitation->delete();
            Session::put('current_organization_id', $this->invitation->organization_id);
            Session::forget('current_team_id');
            Session::flash('success', $joinedTeam
                ? 'You have joined the team '.$team->name.'.'
                : 'You are already a member of this organization.');

            return $this->redirect(route('organizations.show', $this->invitation->organization), navigate: true);
        }

        $this->state = 'ready';

        return null;
    }

    /** Registration URL carrying the token, so signup prefills + returns here. */
    public function registerUrl(): string
    {
        return route('register', ['org_invite' => $this->token]);
    }

    public function accept(): mixed
    {
        // A guest can't join anything — the button isn't rendered for them, but
        // the action is public, so re-check rather than trust the view.
        if (! auth()->check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if (! $this->invitation || $this->invitation->isExpired()) {
            return $this->redirect(route('organizations.index'), navigate: true);
        }

        $user = auth()->user();
        if (strtolower($user->email) !== strtolower($this->invitation->email)) {
            $this->state = 'mismatch';

            return null;
        }

        $this->invitation->organization->users()->attach($user->id, ['role' => $this->invitation->role]);
        $org = $this->invitation->organization;

        // Team invites join the team in the same step. Re-read the team through
        // the organization so a team that was deleted (or moved) between send
        // and accept degrades to a plain org join instead of failing.
        $team = $this->invitation->team_id
            ? $org->teams()->find($this->invitation->team_id)
            : null;
        if ($team && ! $team->users()->where('user_id', $user->id)->exists()) {
            $team->users()->attach($user->id, ['role' => 'member']);
        }

        $this->invitation->delete();
        Session::put('current_organization_id', $org->id);
        Session::forget('current_team_id');
        Session::flash('success', $team
            ? 'You have joined '.$org->name.' on the team '.$team->name.'.'
            : 'You have joined '.$org->name.'.');

        return $this->redirect(route('organizations.show', $org), navigate: true);
    }

    public function decline(): mixed
    {
        if (! auth()->check()) {
            return $this->redirect(route('login'), navigate: true);
        }

        if ($this->invitation) {
            $this->invitation->delete();
        }
        Session::flash('success', 'Invitation declined.');

        return $this->redirect(route('organizations.index'), navigate: true);
    }

    public function render(): View
    {
        // Signed-in users keep the app chrome; guests get the same shell the
        // login/register screens use.
        return view('livewire.invitations.accept')
            ->layout(auth()->check() ? 'layouts.app' : 'layouts.guest-livewire', [
                'title' => 'Invitation',
            ]);
    }
}
