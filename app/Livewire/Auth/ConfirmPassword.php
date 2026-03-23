<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ConfirmPassword extends Component
{
    public string $password = '';

    public string $title = 'Confirm password';

    public function submit(): mixed
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => auth()->user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session()->put('auth.password_confirmed_at', time());

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.confirm-password')
            ->layout('layouts.guest-livewire', ['title' => $this->title]);
    }
}
