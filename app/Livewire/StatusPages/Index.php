<?php

namespace App\Livewire\StatusPages;

use App\Models\StatusPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $description = '';

    public function createPage(): void
    {
        $this->authorize('create', StatusPage::class);

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

        $org->statusPages()->create([
            'user_id' => $user->id,
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,
            'is_public' => true,
        ]);

        $this->reset('name', 'description');
        $this->toastSuccess(__('Status page created.'));
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        $pages = $org
            ? $org->statusPages()->orderBy('name')->get()
            : collect();

        return view('livewire.status-pages.index', [
            'pages' => $pages,
            'hasOrganization' => $org !== null,
        ]);
    }
}
