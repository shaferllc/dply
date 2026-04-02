<aside class="lg:col-span-3 lg:sticky lg:top-8">
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-base font-semibold text-slate-900">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
                    <p class="mt-1 text-sm text-slate-500">
                        @if (($runtimePublication['hostname'] ?? null) || ($runtimePublication['container_ip'] ?? null))
                            {{ $runtimePublication['hostname'] ?? __('Hostname pending') }}
                            @if (! empty($runtimePublication['container_ip']))
                                <span class="font-mono">{{ $runtimePublication['container_ip'] }}</span>
                            @endif
                        @else
                            {{ $server->ip_address ?? __('No IP recorded') }}
                        @endif
                    </p>
                </div>
                @if ($site->visitUrl())
                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                    </a>
                @endif
            </div>
        </div>
        <nav id="site-settings-sidebar" class="p-4" aria-label="{{ __($resourceNoun.' settings sections') }}">
            <ul class="space-y-1.5">
                @foreach ($settingsSidebarItems as $item)
                    <li>
                        <a
                            href="{{ route('sites.show', array_merge([
                                'server' => $server,
                                'site' => $site,
                                'section' => $item['id'],
                            ], $item['id'] === 'routing' ? ['tab' => $routingTab] : [])) }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                'bg-slate-100 text-slate-900' => $section === $item['id'],
                                'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $section !== $item['id'],
                            ])
                        >
                            <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
                <li class="pt-2">
                    <a
                        href="{{ route('servers.sites', $server) }}"
                        wire:navigate
                        class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                        <span>{{ __('Back to :resources', ['resources' => $resourcePlural]) }}</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>
