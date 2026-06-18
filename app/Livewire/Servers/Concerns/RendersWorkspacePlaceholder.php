<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Provides the Livewire lazy-load placeholder() for server workspace tab
 * components. Pair with the #[Lazy] attribute (or ->lazy() on the route):
 * navigating to a tab returns the chrome + skeleton instantly, then a
 * follow-up request hydrates the real (query-heavy) render() output.
 *
 * The active tab + title are derived from the current route via the
 * server_workspace nav map, so a component only needs `use` + #[Lazy] —
 * no per-component metadata. Falls back to deriving the tab key from the
 * route name (servers.settings -> "settings") for routes absent from the nav.
 *
 * @phpstan-require-extends Component
 *
 * @property Server|null $server Set in mount() by InteractsWithServerWorkspace.
 */
trait RendersWorkspacePlaceholder
{
    public function placeholder(): View
    {
        // mount() runs before the placeholder, but guard anyway: a component
        // whose mount() redirected (e.g. non-VM host) never reaches here, and
        // one that hasn't set $server shouldn't fatal the skeleton.
        if ($this->server === null) {
            return view('livewire.servers.partials.workspace-placeholder-empty');
        }

        [$active, $title] = $this->resolveWorkspacePlaceholderChrome(request()->route()?->getName());

        return view('livewire.servers.partials.workspace-placeholder', [
            'server' => $this->server,
            'active' => $active,
            'title' => $title,
        ]);
    }

    /**
     * Map the current route to [active tab key, title] using the workspace
     * nav config (key <-> route). Title is left null when unknown — the
     * layout's title prop is nullable.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveWorkspacePlaceholderChrome(?string $routeName): array
    {
        if (! is_string($routeName) || $routeName === '') {
            return [null, null];
        }

        foreach (config('server_workspace.nav', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['route'] ?? null) === $routeName || ($item['preview_route'] ?? null) === $routeName) {
                return [
                    $item['key'] ?? null,
                    isset($item['label']) ? __($item['label']) : null,
                ];
            }
        }

        return [Str::after($routeName, 'servers.') ?: null, null];
    }
}
