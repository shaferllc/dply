<?php

namespace App\Livewire\Marketing;

use App\Models\ComingSoonSignup as ComingSoonSignupRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class ComingSoonSignup extends Component
{
    public string $email = '';

    public bool $submitted = false;

    public bool $alreadySubscribed = false;

    public function submit(): void
    {
        $validated = $this->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $signup = ComingSoonSignupRecord::subscribe($validated['email'], 'coming-soon');

        $this->email = '';
        $this->submitted = true;
        $this->alreadySubscribed = ! $signup->wasRecentlyCreated;
    }

    public function getSuccessMessageProperty(): string
    {
        if ($this->alreadySubscribed) {
            return __('You are already on the list. We will reach out when access opens up.');
        }

        return __('You are on the list. We will let you know as soon as dply is ready.');
    }

    public function render(): View
    {
        return view('livewire.marketing.coming-soon-signup', [
            'pageTitle' => __('dply early access'),
            'metaDescription' => __('Join the dply early-access list and we will contact you when the product is ready.'),
            'eyebrow' => __('Private preview'),
            'headline' => __('Infrastructure operations, opening soon.'),
            'subheadline' => __('Join the list for launch updates and keep `/login` for existing access while we prepare the live rollout.'),
            'successMessage' => $this->successMessage,
        ])->layout('layouts.status-public', [
            'title' => Str::of(__('dply early access'))->append(' - ', config('app.name'))->value(),
        ]);
    }
}
