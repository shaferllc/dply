<?php

namespace App\Livewire\Projects;

use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public function createProject(): void
    {
        $this->authorize('create', Workspace::class);

        $user = auth()->user();
        $org = $user->currentOrganization();
        if (! $org) {
            session()->flash('error', __('Select an organization first.'));

            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
        ]);

        $org->workspaces()->create([
            'user_id' => $user->id,
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
        ]);

        $this->reset('name', 'description');
        session()->flash('success', __('Project created.'));
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        $workspaces = $org
            ? $org->workspaces()->withCount(['servers', 'sites'])->orderBy('name')->get()
            : collect();

        return view('livewire.projects.index', [
            'workspaces' => $workspaces,
            'hasOrganization' => $org !== null,
        ]);
    }
}
