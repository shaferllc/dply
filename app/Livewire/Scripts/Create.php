<?php

namespace App\Livewire\Scripts;

use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public string $name = '';

    public string $content = "#!/bin/bash\nset -euo pipefail\n\necho \"OK\"\n";

    public string $run_as_user = '';

    public function mount(): void
    {
        $this->authorize('create', Script::class);
    }

    public function save(): mixed
    {
        $this->authorize('create', Script::class);

        $org = Auth::user()->currentOrganization();
        if (! $org) {
            abort(403, __('Select an organization first.'));
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:512000'],
            'run_as_user' => ['nullable', 'string', 'max:64'],
        ], [], [
            'name' => __('Label'),
            'content' => __('Content'),
            'run_as_user' => __('Run as user'),
        ]);

        $script = Script::query()->create([
            'organization_id' => $org->id,
            'user_id' => Auth::id(),
            'name' => $this->name,
            'content' => $this->content,
            'run_as_user' => $this->run_as_user !== '' ? $this->run_as_user : null,
            'source' => Script::SOURCE_USER_CREATED,
            'marketplace_key' => null,
        ]);

        return $this->redirect(route('scripts.edit', $script), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.scripts.create');
    }
}
