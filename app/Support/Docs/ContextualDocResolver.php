<?php

namespace App\Support\Docs;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class ContextualDocResolver
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {}

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

        return $this->defaultFallbackSlug();
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

        $groups = config('docs.groups', []);
        $group = $groups[$groupKey] ?? null;

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

        $markdown = config('docs.markdown', []);
        $page = $markdown[$slug] ?? null;

        if (is_array($page) && is_string($page['title'] ?? null) && $page['title'] !== '') {
            return $page['title'];
        }

        $virtual = config('docs.virtual', []);
        $virtualPage = $virtual[$slug] ?? null;

        if (is_array($virtualPage) && is_string($virtualPage['title'] ?? null) && $virtualPage['title'] !== '') {
            return $virtualPage['title'];
        }

        return null;
    }

    public function fullPageUrlForSlug(string $slug): ?string
    {
        if ($slug === 'docs-index') {
            return route('docs.index');
        }

        if (isset(config('docs.markdown', [])[$slug])) {
            return route('docs.markdown', ['slug' => $slug]);
        }

        $virtual = config('docs.virtual', []);
        $virtualPage = $virtual[$slug] ?? null;

        if (is_array($virtualPage) && is_string($virtualPage['route'] ?? null) && $virtualPage['route'] !== '') {
            return route($virtualPage['route']);
        }

        return null;
    }

    public function isMarkdownSlug(string $slug): bool
    {
        if ($slug === 'docs-index') {
            return false;
        }

        if (isset(config('docs.markdown', [])[$slug])) {
            return true;
        }

        $virtual = config('docs.virtual', []);

        return isset($virtual[$slug]['file']);
    }

    public function isVirtualOnlySlug(string $slug): bool
    {
        $virtual = config('docs.virtual', []);

        return isset($virtual[$slug]) && ! isset($virtual[$slug]['file']);
    }

    public function virtualSummaryForSlug(string $slug): ?string
    {
        $virtual = config('docs.virtual', []);
        $summary = $virtual[$slug]['summary'] ?? null;

        return is_string($summary) && $summary !== '' ? $summary : null;
    }

    /**
     * @return list<array{slug: string, title: string, url: string|null}>
     */
    public function indexEntries(): array
    {
        $entries = [];

        foreach (config('docs.groups', []) as $group) {
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
            return (string) config('contextual-docs.fallbacks.sites', 'sites-and-deploy');
        }

        return (string) config('contextual-docs.fallbacks.default', 'edge-overview');
    }

    private function inferGroupFromSlug(?string $slug): ?string
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        foreach (config('docs.groups', []) as $key => $group) {
            if (! is_array($group)) {
                continue;
            }

            if (in_array($slug, $group['slugs'] ?? [], true)) {
                return (string) $key;
            }
        }

        return null;
    }
}
