<?php

namespace App\Livewire\Scripts;

use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Script::class);
    }

    public function render(): View
    {
        $org = Auth::user()->currentOrganization();
        if (! $org) {
            abort(403, __('Select an organization first.'));
        }

        $query = Script::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('updated_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return view('livewire.scripts.index', [
            'scripts' => $query->paginate(15),
            'organization' => $org,
        ]);
    }
}
