<?php

namespace App\Modules\Marketplace\Livewire\Scripts;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Marketplace extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.scripts';

    use DispatchesToastNotifications;

    /**
     * Optional webserver filter (nginx / caddy / apache). When set, only
     * presets that reference that engine are shown — lets the webserver-config
     * Runbook deep-link straight to the scripts relevant to this site's engine.
     */
    #[Url(as: 'webserver', except: '')]
    public string $webserver = '';

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
        $all = collect(config('script_marketplace', []));

        // Filter to presets relevant to the chosen webserver via their explicit
        // `webservers` tag. A preset matches when it lists the engine, or is
        // tagged '*' (generic web/TLS scripts that apply to every engine).
        // Untagged presets are general-purpose system scripts and stay hidden
        // while an engine filter is active.
        $webserver = strtolower(trim($this->webserver));
        if ($webserver !== '') {
            $all = $all->filter(function (array $p) use ($webserver): bool {
                $tags = $p['webservers'] ?? [];

                return is_array($tags)
                    && (in_array($webserver, $tags, true) || in_array('*', $tags, true));
            });
        }

        $presets = $all
            ->map(fn (array $p, string $k) => [
                'key' => $k,
                'name' => $p['name'] ?? $k,
                'run_as_user' => $p['run_as_user'] ?? null,
            ])
            ->values();

        return view('livewire.scripts.marketplace', [
            'presets' => $presets,
            'webserverFilter' => $webserver,
            'totalPresetCount' => $all->count() === 0 && $webserver !== '' ? 0 : count(config('script_marketplace', [])),
        ]);
    }
}
