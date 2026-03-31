@props([
    'server',
    /** @var string|null Active server panel key (sites, overview, …) for nav highlight */
    'active' => null,
])

@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';
    $workspaceNav = config('server_workspace.nav', []);
@endphp

<div
    class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
    x-data="{ copiedIp: false }"
>
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        <aside class="lg:col-span-3 mb-8 lg:mb-0">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist mb-3">{{ __('Server') }}</h2>
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 p-4 sm:p-5">
                    <p class="truncate text-base font-semibold text-brand-ink">{{ $server->name }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-md bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">SSH</span>
                        @if ($server->ip_address)
                            <span class="font-mono text-xs text-brand-ink">{{ $server->ip_address }}</span>
                            <button
                                type="button"
                                class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                                title="{{ __('Copy IP') }}"
                                @click="navigator.clipboard.writeText(@js($server->ip_address)); copiedIp = true; setTimeout(() => copiedIp = false, 2000)"
                            >
                                <x-heroicon-o-clipboard class="h-4 w-4" />
                            </button>
                            <span x-show="copiedIp" x-cloak class="text-[10px] font-medium text-brand-forest">{{ __('Copied') }}</span>
                        @else
                            <span class="text-xs text-brand-mist">—</span>
                        @endif
                    </div>
                    @if ($server->getSshConnectionString())
                        <p class="mt-3 break-all font-mono text-[11px] leading-relaxed text-brand-moss">{{ $server->getSshConnectionString() }}</p>
                    @endif
                </div>
                <nav class="flex flex-col gap-0.5 p-2" aria-label="{{ __('Server sections') }}">
                    @foreach ($workspaceNav as $item)
                        @php
                            $key = $item['key'];
                            $icon = $item['icon'];
                            $label = __($item['label']);
                            $navHref = server_workspace_nav_item_url($server, $item);
                        @endphp
                        <a
                            href="{{ $navHref }}"
                            wire:navigate
                            @class([
                                $navLink,
                                'bg-brand-sand/60 text-brand-ink' => $active === $key,
                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== $key,
                            ])
                        >
                            @switch($icon)
                                @case('globe-alt')
                                    <x-heroicon-o-globe-alt class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('cpu-chip')
                                    <x-heroicon-o-cpu-chip class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('circle-stack')
                                    <x-heroicon-o-circle-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('clock')
                                    <x-heroicon-o-clock class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('server-stack')
                                    <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('shield-check')
                                    <x-heroicon-o-shield-check class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('key')
                                    <x-heroicon-o-key class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('document-text')
                                    <x-heroicon-o-document-text class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('rocket-launch')
                                    <x-heroicon-o-rocket-launch class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('clipboard-document-list')
                                    <x-heroicon-o-clipboard-document-list class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('wrench-screwdriver')
                                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('cog-8-tooth')
                                    <x-heroicon-o-cog-8-tooth class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                            @endswitch
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>
                <div class="border-t border-brand-ink/10 p-3">
                    <a
                        href="{{ route('servers.index') }}"
                        wire:navigate
                        class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                        {{ __('All servers') }}
                    </a>
                </div>
            </div>
        </aside>

        <div class="lg:col-span-9 min-w-0">
            {{ $slot }}
        </div>
    </div>
</div>
