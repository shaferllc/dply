<?php

namespace App\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class DeleteAccount extends Component
{
    public string $delete_password = '';

    public function deleteAccount(): void
    {
        $this->validate([
            'delete_password' => ['required', 'current_password'],
        ], [], ['delete_password' => __('password')]);

        $user = auth()->user();
        $org = $user->currentOrganization();
        $snapshot = ['user_id' => (string) $user->id, 'email' => $user->email, 'name' => $user->name];

        if ($org) {
            audit_log($org, $user, 'user.account_deleted', null, $snapshot, null);
        }

        Auth::logout();
        $user->delete();
        Session::invalidate();
        Session::regenerateToken();

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.profile.delete-account');
    }
}
