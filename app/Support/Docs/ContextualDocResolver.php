<?php

namespace App\Support\Docs;

use App\Models\Site;
use App\Services\Docs\DocsManifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class ContextualDocResolver
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {}

    private function manifest(): DocsManifest
    {
        return app(DocsManifest::class);
    }

    /**
     * Resolve the doc slug for the current or overridden context.
     */
    public function resolve(?string $overrideSlug = null, ?string $docRoute = null, ?string $docSlug = null): string
    {
        if (is_string($overrideSlug) && $overrideSlug !== '') {
            return $overrideSlug;
        }

        $fromProps = $this->resolveFromDocProps($docRoute, $docSlug);
        if ($fromProps !== null) {
            return $fromProps;
        }

        $match = $this->matchRoute();
        if ($match !== null) {
            if (($match['mode'] ?? null) === 'index') {
                return 'docs-index';
            }

            if (is_string($match['slug'] ?? null) && $match['slug'] !== '') {
                return $match['slug'];
            }
        }

        $fromSiteContext = $this->resolveFromSiteContext();
        if ($fromSiteContext !== null) {
            return $fromSiteContext;
        }

        return $this->defaultFallbackSlug();
    }

    /**
     * Resolve a doc slug from a site workspace section without relying on the current route.
     */
    public function resolveForSiteSection(Site $site, string $section): string
    {
        foreach (config('contextual-docs.routes', []) as $entry) {
            if (! is_array($entry) || ($entry['route'] ?? null) !== 'sites.show') {
                continue;
            }

            $params = $entry['params'] ?? [];
            if (($params['section'] ?? null) !== $section) {
                continue;
            }

            if (! $this->matchesWhenForSite($entry['when'] ?? null, $site)) {
                continue;
            }

            $slug = $entry['slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        if ($site->usesEdgeRuntime()) {
            foreach (config('contextual-docs.routes', []) as $entry) {
                if (! is_array($entry) || ($entry['route'] ?? null) !== 'sites.show') {
                    continue;
                }

                if (($entry['params'] ?? []) !== []) {
                    continue;
                }

                if (! $this->matchesWhenForSite($entry['when'] ?? null, $site)) {
                    continue;
                }

                $slug = $entry['slug'] ?? null;
                if (is_string($slug) && $slug !== '') {
                    return $slug;
                }
            }

            return (string) config('contextual-docs.fallbacks.edge', 'edge-overview');
        }

        $sectionSlugs = config('contextual-docs.site_section_slugs', []);
        $sectionSlug = is_array($sectionSlugs) ? ($sectionSlugs[$section] ?? null) : null;
        if (is_string($sectionSlug) && $sectionSlug !== '') {
            return $sectionSlug;
        }

        return (string) config('contextual-docs.fallbacks.sites', 'vm-site-overview');
    }

    /**
     * Resolve contextual docs for a server workspace sidebar section key.
     */
    public function resolveForServerWorkspace(?string $activeKey): string
    {
        if (! is_string($activeKey) || $activeKey === '') {
            return (string) config('contextual-docs.fallbacks.servers', 'server-overview');
        }

        $workspaceSlugs = config('contextual-docs.server_workspace_slugs', []);
        $slug = is_array($workspaceSlugs) ? ($workspaceSlugs[$activeKey] ?? null) : null;

        return is_string($slug) && $slug !== ''
            ? $slug
            : (string) config('contextual-docs.fallbacks.servers', 'server-overview');
    }

    /**
     * @return array{key: string, label: string, slugs: list<string>}|null
     */
    public function guideGroup(?string $slug = null): ?array
    {
        $match = $this->matchRoute();
        $groupKey = is_array($match) ? ($match['group'] ?? null) : null;

        if (! is_string($groupKey) || $groupKey === '') {
            $groupKey = $this->inferGroupFromSlug($slug);
        }

        if (! is_string($groupKey) || $groupKey === '') {
            return null;
        }

        $group = $this->manifest()->groups()[$groupKey] ?? null;

        if (! is_array($group)) {
            return null;
        }

        return [
            'key' => $groupKey,
            'label' => (string) ($group['label'] ?? $groupKey),
            'slugs' => array_values(array_filter(
                $group['slugs'] ?? [],
                static fn ($value): bool => is_string($value) && $value !== '',
            )),
        ];
    }

    /**
     * @return list<array{label: string, slug: string|null}>
     */
    public function breadcrumbsForSlug(string $slug): array
    {
        if ($slug === 'docs-index') {
            return [
                ['label' => __('Documentation'), 'slug' => 'docs-index'],
            ];
        }

        $items = [
            ['label' => __('Documentation'), 'slug' => 'docs-index'],
        ];

        $group = $this->guideGroup($slug);
        if ($group !== null && ($group['label'] ?? '') !== '') {
            $items[] = [
                'label' => (string) $group['label'],
                'slug' => null,
            ];
        }

        $title = $this->titleForSlug($slug);
        if ($title !== null) {
            $items[] = [
                'label' => $title,
                'slug' => $slug,
            ];
        }

        return $items;
    }

    public function titleForSlug(string $slug): ?string
    {
        if ($slug === 'docs-index') {
            return __('Documentation');
        }

        $doc = $this->manifest()->find($slug);

        return is_array($doc) && is_string($doc['title'] ?? null) && $doc['title'] !== ''
            ? $doc['title']
            : null;
    }

    public function fullPageUrlForSlug(string $slug): ?string
    {
        if ($slug === 'docs-index') {
            return route('docs.index');
        }

        $doc = $this->manifest()->find($slug);
        if (! is_array($doc)) {
            return null;
        }

        if (is_string($doc['route'] ?? null) && $doc['route'] !== '') {
            return route($doc['route']);
        }

        if (! empty($doc['file'])) {
            return route('docs.markdown', ['slug' => $slug]);
        }

        return null;
    }

    public function isMarkdownSlug(string $slug): bool
    {
        if ($slug === 'docs-index') {
            return false;
        }

        $doc = $this->manifest()->find($slug);

        return is_array($doc) && ! empty($doc['file']);
    }

    public function isVirtualOnlySlug(string $slug): bool
    {
        $doc = $this->manifest()->find($slug);

        return is_array($doc) && empty($doc['file']) && ! empty($doc['route']);
    }

    public function virtualSummaryForSlug(string $slug): ?string
    {
        if (! $this->isVirtualOnlySlug($slug)) {
            return null;
        }

        $summary = $this->manifest()->find($slug)['description'] ?? null;

        return is_string($summary) && $summary !== '' ? $summary : null;
    }

    /**
     * @return list<array{slug: string, title: string, url: string|null}>
     */
    public function indexEntries(): array
    {
        $entries = [];

        foreach ($this->manifest()->groups() as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['slugs'] ?? [] as $slug) {
                if (! is_string($slug) || $slug === '' || isset($entries[$slug])) {
                    continue;
                }

                $title = $this->titleForSlug($slug);
                if ($title === null) {
                    continue;
                }

                $entries[$slug] = [
                    'slug' => $slug,
                    'title' => $title,
                    'url' => $this->fullPageUrlForSlug($slug),
                ];
            }
        }

        return array_values($entries);
    }

    private function resolveFromDocProps(?string $docRoute, ?string $docSlug): ?string
    {
        if ($docRoute === 'docs.markdown' && is_string($docSlug) && $docSlug !== '') {
            return $docSlug;
        }

        if ($docRoute === 'docs.create-first-server') {
            return 'create-first-server';
        }

        if ($docRoute === 'docs.connect-provider') {
            return 'connect-provider';
        }

        if ($docRoute === 'docs.api') {
            return 'api';
        }

        if ($docRoute === 'docs.index') {
            return 'docs-index';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchRoute(): ?array
    {
        $currentRoute = Route::currentRouteName();
        if (! is_string($currentRoute) || $currentRoute === '') {
            return null;
        }

        foreach (config('contextual-docs.routes', []) as $entry) {
            if (! is_array($entry) || ($entry['route'] ?? null) !== $currentRoute) {
                continue;
            }

            if (! $this->matchesWhen($entry['when'] ?? null)) {
                continue;
            }

            if (! $this->matchesParams($entry['params'] ?? [])) {
                continue;
            }

            return $entry;
        }

        $siteRouteSlugs = config('contextual-docs.site_route_slugs', []);
        $siteRouteSlug = is_array($siteRouteSlugs) ? ($siteRouteSlugs[$currentRoute] ?? null) : null;
        if (is_string($siteRouteSlug) && $siteRouteSlug !== '') {
            return [
                'slug' => $siteRouteSlug,
                'group' => 'byo-sites',
            ];
        }

        foreach (config('server_workspace.nav', []) as $navItem) {
            if (! is_array($navItem)) {
                continue;
            }

            $navKey = $navItem['key'] ?? null;
            $navRoute = $navItem['route'] ?? null;
            $previewRoute = $navItem['preview_route'] ?? null;

            if (! is_string($navKey) || $navKey === '') {
                continue;
            }

            if ($currentRoute !== $navRoute && $currentRoute !== $previewRoute) {
                continue;
            }

            $workspaceSlugs = config('contextual-docs.server_workspace_slugs', []);
            $slug = is_array($workspaceSlugs) ? ($workspaceSlugs[$navKey] ?? null) : null;
            if (is_string($slug) && $slug !== '') {
                return [
                    'slug' => $slug,
                    'group' => 'servers',
                ];
            }
        }

        return null;
    }

    private function matchesWhen(mixed $when): bool
    {
        if ($when === null) {
            return true;
        }

        $site = $this->routeSite();

        return match ($when) {
            'edge_site' => $site instanceof Site && $site->usesEdgeRuntime(),
            'edge_site_provisioning' => $site instanceof Site
                && $site->usesEdgeRuntime()
                && ! $site->isReadyForWorkspace(),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function matchesParams(array $params): bool
    {
        if ($params === []) {
            return true;
        }

        $request = $this->request ?? request();

        foreach ($params as $key => $expected) {
            $actual = $request->route($key) ?? $request->query($key) ?? $request->input($key);

            if ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    private function routeSite(): ?Site
    {
        $site = ($this->request ?? request())->route('site');

        return $site instanceof Site ? $site : null;
    }

    private function resolveFromSiteContext(): ?string
    {
        $site = $this->routeSite();
        if (! $site instanceof Site) {
            return null;
        }

        $section = $this->sectionFromRequestPath()
            ?? ($this->request ?? request())->route('section');

        if (! is_string($section) || $section === '') {
            $section = 'general';
        }

        return $this->resolveForSiteSection($site, $section);
    }

    private function sectionFromRequestPath(): ?string
    {
        $path = trim(($this->request ?? request())->path(), '/');

        if (preg_match('#^servers/[^/]+/sites/[^/]+/([^/?]+)#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function matchesWhenForSite(mixed $when, Site $site): bool
    {
        return match ($when) {
            'edge_site' => $site->usesEdgeRuntime() && $site->isReadyForWorkspace(),
            'edge_site_provisioning' => $site->usesEdgeRuntime() && ! $site->isReadyForWorkspace(),
            default => true,
        };
    }

    private function defaultFallbackSlug(): string
    {
        $site = $this->routeSite();
        if ($site instanceof Site && $site->usesEdgeRuntime()) {
            return (string) config('contextual-docs.fallbacks.edge', 'edge-overview');
        }

        $route = Route::currentRouteName();
        if (is_string($route) && str_starts_with($route, 'edge.')) {
            return (string) config('contextual-docs.fallbacks.edge', 'edge-overview');
        }

        if (is_string($route) && (
            str_starts_with($route, 'settings.')
            || str_starts_with($route, 'billing.')
            || str_starts_with($route, 'organizations.')
        )) {
            return (string) config('contextual-docs.fallbacks.organization', 'org-roles-and-limits');
        }

        if (is_string($route) && (
            str_starts_with($route, 'servers.')
            || str_starts_with($route, 'sites.')
        )) {
            $site = $this->routeSite();
            if ($site instanceof Site && $site->usesEdgeRuntime()) {
                return (string) config('contextual-docs.fallbacks.edge', 'edge-overview');
            }

            if (str_starts_with($route, 'servers.')) {
                return (string) config('contextual-docs.fallbacks.servers', 'server-overview');
            }

            return (string) config('contextual-docs.fallbacks.sites', 'vm-site-overview');
        }

        return (string) config('contextual-docs.fallbacks.default', 'edge-overview');
    }

    private function inferGroupFromSlug(?string $slug): ?string
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return $this->manifest()->groupForSlug($slug);
    }
}
