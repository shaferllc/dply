<?php

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Marketplace extends Component
{
    use DispatchesToastNotifications;

    public function mount(): void
    {
        $this->authorize('create', Script::class);
    }

    public function clonePreset(string $key): mixed
    {
        $this->authorize('create', Script::class);

        $org = Auth::user()->currentOrganization();
        if (! $org) {
            abort(403, __('Select an organization first.'));
        }

        $presets = config('script_marketplace', []);
        $preset = $presets[$key] ?? null;
        if (! is_array($preset) || empty($preset['name']) || ! isset($preset['content'])) {
            $this->addError('marketplace', __('This marketplace script is not available.'));

            return null;
        }

        $script = Script::query()->create([
            'organization_id' => $org->id,
            'user_id' => Auth::id(),
            'name' => $preset['name'],
            'content' => (string) $preset['content'],
            'run_as_user' => isset($preset['run_as_user']) && $preset['run_as_user'] !== ''
                ? (string) $preset['run_as_user']
                : null,
            'source' => Script::SOURCE_MARKETPLACE,
            'marketplace_key' => $key,
        ]);

        $this->toastSuccess(__('Script added to your organization. You can edit it below.'));

        return $this->redirect(route('scripts.edit', $script), navigate: true);
    }

    public function render(): View
    {
        $presets = collect(config('script_marketplace', []))
            ->map(fn (array $p, string $k) => [
                'key' => $k,
                'name' => $p['name'] ?? $k,
                'run_as_user' => $p['run_as_user'] ?? null,
            ])
            ->values();

        return view('livewire.scripts.marketplace', [
            'presets' => $presets,
        ]);
    }
}
