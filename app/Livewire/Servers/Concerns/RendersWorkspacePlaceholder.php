<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Features\SupportQueryString\BaseUrl;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;

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
    /**
     * Seed #[Url]-backed string properties from the query string.
     *
     * MUST be called at the top of every placeholder(), including overrides —
     * otherwise a deep-linked sub-tab paints the wrong tab.
     *
     * Livewire applies #[Url] from the attribute's own mount hook
     * ({@see BaseUrl::mount}), but
     * {@see SupportLazyLoading::mount}
     * renders the placeholder from inside *its* mount hook, which runs first.
     * So while the placeholder is being built, a property like `$section` still
     * holds its class default even though ?tab= is sitting right there on the
     * URL. Every tabbed workspace page therefore painted its DEFAULT sub-tab and
     * then jumped to the requested one when the hydrate response landed — which
     * reads as the page starting over on the wrong tab.
     *
     * (Livewire's own lifecycle hooks are no help here: #[Lazy] calls
     * skipMount(), so boot/mount/booted never fire on the document request.)
     *
     * Deliberately narrow: document GETs only — Livewire owns the value on every
     * subsequent update — string-typed properties only, and only when the
     * parameter is actually present.
     */
    protected function seedUrlPropertiesFromRequest(): void
    {
        $request = request();

        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            return;
        }

        foreach ((new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Url::class);
            if ($attributes === []) {
                continue;
            }

            $type = $property->getType();
            if (! $type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                continue;
            }

            $as = $attributes[0]->newInstance()->as;
            $key = is_string($as) && $as !== '' ? $as : $property->getName();
            $value = $request->query($key);

            if (is_string($value)) {
                $property->setValue($this, $value);
            }
        }
    }

    public function placeholder(): View
    {
        $this->seedUrlPropertiesFromRequest();

        // #[Lazy] skips mount() on the document request, so $server may well be
        // unset here — the skeleton must not fatal on that.
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
