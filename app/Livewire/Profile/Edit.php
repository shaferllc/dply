<?php

namespace App\Livewire\Profile;

use App\Http\Controllers\SessionController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public string $name = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $delete_password = '';

    public bool $showDeleteModal = false;

    public bool $verificationLinkSent = false;

    public function mount(): void
    {
        $user = $this->user();
        $this->name = $user->name;
        $this->email = $user->email;
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

    public function updateProfile(): void
    {
        $user = $this->user();
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->fill(['name' => $this->name, 'email' => $this->email]);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        $this->dispatch('profile-updated');
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
        $deleted = \Illuminate\Support\Facades\DB::table($table)
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
        \Illuminate\Support\Facades\DB::table($table)
            ->where('user_id', $userId)
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->dispatch('sessions-revoked');
    }

    public function deleteAccount(): void
    {
        $this->validate([
            'delete_password' => ['required', 'current_password'],
        ], [], ['delete_password' => __('password')]);

        $user = $this->user();
        Auth::logout();
        $user->delete();
        Session::invalidate();
        Session::regenerateToken();

        $this->redirect('/', navigate: true);
    }

    public function openDeleteModal(): void
    {
        $this->resetValidation();
        $this->delete_password = '';
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->reset(['delete_password']);
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
