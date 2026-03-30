<?php

namespace App\Livewire\Organizations;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public string $name = '';

    public function store(): mixed
    {
        if (! auth()->user()->hasVerifiedEmail()) {
            Session::flash('error', __('Please verify your email address before creating an organization.'));

            return $this->redirect(route('verification.notice'), navigate: true);
        }

        $this->validate([
            'name' => 'required|string|max:255',
        ]);

        $slug = Str::slug($this->name);
        $base = $slug;
        $i = 0;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $org = Organization::create([
            'name' => $this->name,
            'slug' => $slug,
        ]);
        $org->users()->attach(auth()->id(), ['role' => 'owner']);
        $org->attachUserToDefaultTeam(auth()->user());
        session(['current_organization_id' => $org->id]);
        Session::forget('current_team_id');

        Session::flash('success', 'Organization created.');

        return $this->redirect(route('organizations.show', $org), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.organizations.create');
    }
}
