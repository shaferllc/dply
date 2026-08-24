<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Livewire\Scripts;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Script;
use App\Modules\Marketplace\Scripts\CloneScriptPreset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Inline script-preset picker rendered as a modal so operators can browse and
 * clone marketplace presets without leaving the page they're on. Drop it on any
 * page and open it with `$dispatch('open-modal', 'script-marketplace-modal')`;
 * pass `:webserver` to pre-scope the list to a site's engine (see the
 * webserver-config Runbook). Filtering uses the `webservers`
 * tag with the '*' wildcard.
 */
class MarketplaceModal extends Component
{
    use DispatchesToastNotifications;

    public const MODAL_NAME = 'script-marketplace-modal';

    /** Engine scope to filter presets by (nginx/caddy/apache); '' shows all. */
    public string $webserver = '';

    /** Free-text filter within the modal. */
    public string $search = '';

    public function mount(string $webserver = ''): void
    {
        $this->webserver = strtolower(trim($webserver));
    }

    public function clonePreset(string $key, CloneScriptPreset $cloner): void
    {
        $this->authorize('create', Script::class);

        $user = Auth::user();
        $org = $user?->currentOrganization();
        if ($user === null || $org === null) {
            $this->toastError(__('Select an organization first.'));

            return;
        }

        $script = $cloner->clone($key, $org, $user);
        if ($script === null) {
            $this->toastError(__('This marketplace script is not available.'));

            return;
        }

        $this->toastSuccess(__('“:name” added to your scripts.', ['name' => $script->name]));
        $this->dispatch('script-cloned', scriptId: $script->id);
    }

    public function render(): View
    {
        $webserver = $this->webserver;
        $needle = mb_strtolower(trim($this->search));

        $presets = collect(config('script_marketplace', []))
            ->filter(function (array $p) use ($webserver, $needle): bool {
                if ($webserver !== '') {
                    $tags = $p['webservers'] ?? [];
                    $matchesEngine = is_array($tags)
                        && (in_array($webserver, $tags, true) || in_array('*', $tags, true));
                    if (! $matchesEngine) {
                        return false;
                    }
                }

                if ($needle !== '') {
                    return str_contains(mb_strtolower((string) ($p['name'] ?? '')), $needle);
                }

                return true;
            })
            ->map(fn (array $p, string $k) => [
                'key' => $k,
                'name' => $p['name'] ?? $k,
                'run_as_user' => $p['run_as_user'] ?? null,
            ])
            ->values();

        return view('livewire.scripts.marketplace-modal', [
            'presets' => $presets,
        ]);
    }
}
