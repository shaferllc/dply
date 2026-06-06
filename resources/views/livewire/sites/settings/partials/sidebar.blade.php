@php
    $card = 'dply-card overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';

    $sidebarPrimaryHostname = optional($site->primaryDomain())->hostname
        ?? ($runtimePublication['hostname'] ?? null)
        ?? $site->name;
    $sidebarVisitUrl = $site->visitUrl();
    $sidebarUrlSeed = (string) ($sidebarPrimaryHostname ?: $site->name ?: $site->id);
    $sidebarHash = hexdec(substr(sha1($sidebarUrlSeed), 0, 12));
    $sidebarHueA = $sidebarHash % 360;
    $sidebarHueB = ($sidebarHueA + 60 + ((int) (($sidebarHash >> 4) % 120))) % 360;
    $sidebarInitials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $sidebarUrlSeed) ?: 'S', 0, 2));
    $sidebarAvatarStyle = "background-image: linear-gradient(135deg, hsl({$sidebarHueA}deg 65% 56%) 0%, hsl({$sidebarHueB}deg 65% 42%) 100%);";
@endphp

<aside class="lg:col-span-3 mb-8 lg:mb-0"
    x-data="{
        copiedUrl: false,
    }"
>
    <div class="{{ $card }}">
        <div class="border-b border-brand-ink/10 p-4 sm:p-5">
            <a href="{{ route('servers.sites', $server) }}" wire:navigate
                class="-ms-1 mb-3 inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 text-xs font-medium text-brand-moss transition-colors hover:bg-brand-sand/50 hover:text-brand-ink">
                <x-heroicon-o-arrow-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Back to sites') }}
            </a>
            <div class="flex items-start gap-3">
                @if ($site->logoUrl())
                    <img src="{{ $site->logoUrl() }}" alt="{{ $site->name }}" class="h-12 w-12 shrink-0 rounded-2xl object-cover bg-white shadow-sm ring-1 ring-brand-ink/10" />
                @else
                    <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white font-semibold text-base shadow-sm ring-1 ring-brand-ink/10" style="{{ $sidebarAvatarStyle }}">
                        {{ $sidebarInitials }}
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold text-brand-ink">{{ $sidebarPrimaryHostname }}</p>
                    @if ($server->workspace)
                        @feature('surface.projects')
                            <p class="mt-0.5 truncate text-xs text-brand-moss">
                                <a href="{{ route('projects.resources', $server->workspace) }}" wire:navigate class="font-medium text-brand-ink hover:text-brand-sage">{{ $server->workspace->name }}</a>
                            </p>
                        @endfeature
                    @endif
                </div>
            </div>

            <div class="mt-3 flex items-center gap-1.5">
                <span class="shrink-0 rounded-md bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">URL</span>
                @if ($sidebarVisitUrl || $sidebarPrimaryHostname)
                    @php
                        $sidebarDisplayUrl = $sidebarVisitUrl ?: $sidebarPrimaryHostname;
                    @endphp
                    <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $sidebarDisplayUrl }}">{{ $sidebarDisplayUrl }}</span>
                    <button
                        type="button"
                        class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        title="{{ __('Copy URL') }}"
                        @click="navigator.clipboard.writeText(@js($sidebarDisplayUrl)); copiedUrl = true; setTimeout(() => copiedUrl = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" />
                    </button>
                    @if ($sidebarVisitUrl)
                        <a
                            href="{{ $sidebarVisitUrl }}"
                            target="_blank"
                            rel="noreferrer"
                            title="{{ __('Open site') }}"
                            class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                        </a>
                    @endif
                    <span x-show="copiedUrl" x-cloak class="text-[10px] font-medium text-brand-forest">{{ __('Copied') }}</span>
                @else
                    <span class="text-xs text-brand-mist">—</span>
                @endif
            </div>
        </div>
        <nav id="site-settings-sidebar" class="flex flex-col gap-0.5 p-2" aria-label="{{ __($resourceNoun.' settings sections') }}">
            @php
                $groupLabels = (array) config('site_settings.nav_groups', []);
                $orderedGroupKeys = [];
                foreach ($settingsSidebarItems as $i) {
                    $gk = $i['group'] ?? '_ungrouped';
                    if (! in_array($gk, $orderedGroupKeys, true)) {
                        $orderedGroupKeys[] = $gk;
                    }
                }
                $itemsByGroup = collect($settingsSidebarItems)->groupBy(fn ($i) => $i['group'] ?? '_ungrouped');
            @endphp
            @foreach ($orderedGroupKeys as $groupKey)
                @php $itemsInGroup = $itemsByGroup[$groupKey] ?? collect(); @endphp
                @if ($itemsInGroup->isEmpty())
                    @continue
                @endif
                @if ($groupKey !== '_ungrouped' && isset($groupLabels[$groupKey]))
                    <p class="{{ ! $loop->first ? 'mt-3 ' : '' }}px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        {{ __($groupLabels[$groupKey]) }}
                    </p>
                @endif
                @foreach ($itemsInGroup as $item)
                    @php
                        $isChild = ! empty($item['parent'] ?? null);
                        if (! empty($item['route'] ?? null)) {
                            $routeArgs = match ($item['route_params'] ?? null) {
                                'server_only' => ['server' => $server],
                                // server-level route, but carry the site as a query
                                // param so the target can render in site context.
                                'server_with_site' => ['server' => $server, 'site' => $site->id],
                                'organization' => ['organization' => $site->organization_id ?? auth()->user()?->currentOrganization()?->id],
                                default => ['server' => $server, 'site' => $site],
                            };
                            $href = route($item['route'], $routeArgs);
                        } else {
                            $href = route('sites.show', array_merge([
                                'server' => $server,
                                'site' => $site,
                                'section' => $item['id'],
                            ], $item['id'] === 'routing' ? ['tab' => $routingTab] : [], $item['id'] === 'laravel-stack' ? ['laravel_tab' => $laravel_tab ?? 'commands'] : []));
                        }
                    @endphp
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        @class([
                            $navLink,
                            // Children indent as a whole pill (margin, not padding) so the
                            // active highlight hugs the content instead of leaving a wide
                            // empty gutter on the left.
                            'ms-4 !w-auto' => $isChild,
                            'bg-brand-sand/60 text-brand-ink' => $section === $item['id'],
                            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $section !== $item['id'],
                        ])
                    >
                        @if ($isChild)
                            <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0 opacity-70" />
                        @else
                            <x-dynamic-component :component="$item['icon']" class="h-5 w-5 shrink-0 opacity-90" />
                        @endif
                        <span class="flex-1 truncate">{{ $item['label'] }}</span>
                        {{-- Only show the open-error count when Errors is actually
                             live — not while it's a "Soon" (preview_only) item. --}}
                        @if ($item['id'] === 'errors' && empty($item['preview_only']))
                            @php $openErrorCount = \App\Models\ErrorEvent::query()->where('site_id', $site->id)->whereNull('dismissed_at')->count(); @endphp
                            @if ($openErrorCount > 0)
                                <span class="shrink-0 rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">{{ $openErrorCount > 99 ? '99+' : $openErrorCount }}</span>
                            @endif
                        @endif
                        @if (! empty($item['preview_only']))
                            <span class="shrink-0 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-brand-moss">
                                {{ __('Soon') }}
                            </span>
                        @endif
                        @if (! empty($item['needs_setup']))
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                role="img"
                                aria-label="{{ __('Setup required') }}"
                                title="{{ __('Supervisor is not installed yet. Open this section to set it up.') }}"
                            ></span>
                        @endif
                    </a>
                @endforeach
            @endforeach
        </nav>
        <div class="border-t border-brand-ink/10 p-3">
            @if ($site->usesEdgeRuntime())
                <a
                    href="{{ route('edge.index') }}"
                    wire:navigate
                    class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                    {{ __('Back to Edge sites') }}
                </a>
            @else
                <a
                    href="{{ route('servers.sites', $server) }}"
                    wire:navigate
                    class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                    {{ __('Back to :resources', ['resources' => $resourcePlural]) }}
                </a>
            @endif
        </div>
    </div>
</aside>
