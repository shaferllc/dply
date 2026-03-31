<?php

namespace App\Livewire\Profile;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Referrals extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.profile.referrals', [
            'referralUrl' => route('register', ['referrer' => $user->referral_code], true),
            'referredUsers' => $user->referredUsers()->orderByDesc('created_at')->get(),
            'bonusCreditCents' => (int) config('referral.bonus_credit_cents', 0),
            'bonusDescription' => (string) config('referral.bonus_description', ''),
        ]);
    }
}
