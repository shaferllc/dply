<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\BetaInvitation;
use App\Models\ComingSoonSignup;
use App\Models\User;
use App\Notifications\BetaInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Platform-admin beta-invite issuance. Admin-only (no peer invites during beta)
 * so the population — and the per-org free-CX22 spend — stays under direct
 * control. Three entry points: single email, bulk paste, and "invite from the
 * coming-soon waitlist". Plus resend / revoke for the issued list.
 */
#[Layout('layouts.admin')]
class BetaInvites extends Component
{
    use AuthorizesPlatformAdmin;
    use DispatchesToastNotifications;

    /** Single or bulk: comma / newline / space separated addresses. */
    public string $emails = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function sendInvites(): void
    {
        $this->authorizePlatformAdmin();

        $candidates = collect(preg_split('/[\s,;]+/', $this->emails, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $e) => Str::lower(trim($e)))
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            $this->toastError(__('Enter at least one email address.'));

            return;
        }

        $source = $candidates->count() > 1 ? BetaInvitation::SOURCE_BULK : BetaInvitation::SOURCE_SINGLE;
        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $email) {
            if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
                $skipped++;

                continue;
            }

            // New-signups-only: don't invite an address that already has an account.
            if (User::where('email', $email)->exists()) {
                $skipped++;

                continue;
            }

            $this->issueAndSend($email, $source);
            $sent++;
        }

        $this->emails = '';
        $this->toastSuccess(trans_choice(':count invite sent.|:count invites sent.', $sent, ['count' => $sent])
            .($skipped > 0 ? ' '.__(':n skipped (invalid or already registered).', ['n' => $skipped]) : ''));
    }

    public function inviteFromWaitlist(string $email): void
    {
        $this->authorizePlatformAdmin();

        $email = Str::lower(trim($email));

        if (User::where('email', $email)->exists()) {
            $this->toastError(__('That address already has an account.'));

            return;
        }

        $this->issueAndSend($email, BetaInvitation::SOURCE_WAITLIST);
        $this->toastSuccess(__('Invite sent to :email.', ['email' => $email]));
    }

    public function resend(string $id): void
    {
        $this->authorizePlatformAdmin();

        $invitation = BetaInvitation::findOrFail($id);

        if (! $invitation->isRedeemable()) {
            $this->toastError(__('That invite can no longer be resent.'));

            return;
        }

        Notification::route('mail', $invitation->email)
            ->notify(new BetaInvitationNotification($invitation));

        $this->toastSuccess(__('Invite resent to :email.', ['email' => $invitation->email]));
    }

    public function revoke(string $id): void
    {
        $this->authorizePlatformAdmin();

        $invitation = BetaInvitation::findOrFail($id);
        $invitation->revoke();

        $this->toastSuccess(__('Invite revoked.'));
    }

    private function issueAndSend(string $email, string $source): void
    {
        $invitation = BetaInvitation::issue($email, auth()->user(), $source);

        Notification::route('mail', $email)
            ->notify(new BetaInvitationNotification($invitation));
    }

    public function render(): View
    {
        $invitations = BetaInvitation::query()
            ->with('inviter')
            ->latest()
            ->limit(100)
            ->get();

        // Waitlist signups with no live or redeemed invite and no account yet —
        // the pool to draw from.
        $invitedEmails = BetaInvitation::query()
            ->where(fn ($q) => $q->whereNull('revoked_at'))
            ->pluck('email')
            ->map(fn ($e) => Str::lower($e))
            ->unique();

        $existingUsers = User::query()->pluck('email')->map(fn ($e) => Str::lower($e))->unique();

        $waitlist = ComingSoonSignup::query()
            ->latest()
            ->limit(200)
            ->get()
            ->reject(fn (ComingSoonSignup $s) => $invitedEmails->contains(Str::lower($s->email))
                || $existingUsers->contains(Str::lower($s->email)))
            ->take(50)
            ->values();

        return view('livewire.admin.beta-invites', [
            'invitations' => $invitations,
            'waitlist' => $waitlist,
        ]);
    }
}
