<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Livewire\Scripts;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Inline script-preset picker rendered as a modal so operators can browse and
 * clone marketplace presets without leaving the page they're on. Drop it on any
 * page and open it with `$dispatch('open-modal', 'script-marketplace-modal')`;
 * pass `:webserver` to pre-scope the list to a site's engine (see the
 * webserver-config Runbook). Filtering mirrors {@see Marketplace} — the
 * `webservers` tag with the '*' wildcard.
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

    public function clonePreset(string $key): void
    {
        $this->authorize('create', Script::class);

        $org = Auth::user()?->currentOrganization();
        if (! $org) {
            $this->toastError(__('Select an organization first.'));

            return;
        }

        $preset = config('script_marketplace.'.$key);
        if (! is_array($preset) || empty($preset['name']) || ! isset($preset['content'])) {
            $this->toastError(__('This marketplace script is not available.'));

            return;
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

        $this->toastSuccess(__('“:name” added to your scripts.', ['name' => $preset['name']]));
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
