{{--
  Existing servers on the selected provider (region + role).
  Expects: $existingProviderServers (list), $regionLabels (value => label map).
--}}
@php
    $existingProviderServers = $existingProviderServers ?? [];
    $regionLabels = collect($regionLabels ?? [])->all();
@endphp

@if ($existingProviderServers !== [])
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-map-pin class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Existing fleet') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Where your servers are installed') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Servers already on this provider. Pick a nearby region or co-locate with an existing role.') }}
                </p>
            </div>
        </div>
        <div class="divide-y divide-brand-ink/8">
            @foreach ($existingProviderServers as $server)
                @php
                    $regionValue = (string) ($server['region'] ?? '');
                    $regionLabel = $regionLabels[$regionValue] ?? ($regionValue !== '' ? strtoupper($regionValue) : __('Unknown region'));
                @endphp
                <div wire:key="existing-server-{{ $server['id'] }}" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
                    <div class="min-w-0">
                        <a
                            href="{{ route('servers.overview', $server['id']) }}"
                            wire:navigate
                            class="truncate text-sm font-semibold text-brand-ink underline decoration-brand-ink/20 underline-offset-2 transition hover:text-brand-forest hover:decoration-brand-forest/40"
                        >
                            {{ $server['name'] }}
                        </a>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-moss">
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-o-map-pin class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                                {{ $regionLabel }}
                                @if ($regionValue !== '' && $regionLabel !== strtoupper($regionValue))
                                    <span class="font-mono text-[10px] uppercase text-brand-mist">{{ $regionValue }}</span>
                                @endif
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 text-[10px] font-medium text-brand-ink ring-1 ring-brand-ink/8">
                                {{ $server['role_label'] }}
                            </span>
                            @if (($server['sites_count'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1">
                                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                                    {{ trans_choice(':count site|:count sites', $server['sites_count'], ['count' => $server['sites_count']]) }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <span @class([
                        'inline-flex shrink-0 items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                        'border-emerald-200 bg-emerald-50 text-emerald-700' => ($server['status'] ?? '') === 'ready',
                        'border-amber-200 bg-amber-50 text-amber-800' => in_array($server['status'] ?? '', ['pending', 'provisioning'], true),
                        'border-rose-200 bg-rose-50 text-rose-700' => in_array($server['status'] ?? '', ['error', 'disconnected'], true),
                        'border-brand-ink/10 bg-brand-sand/40 text-brand-moss' => ! in_array($server['status'] ?? '', ['ready', 'pending', 'provisioning', 'error', 'disconnected'], true),
                    ])>
                        {{ ucfirst((string) ($server['status'] ?? 'unknown')) }}
                    </span>
                </div>
            @endforeach
        </div>
    </section>
@endif
