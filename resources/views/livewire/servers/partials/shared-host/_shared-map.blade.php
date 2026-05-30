@php
    $resources = $sharedMap['shared_resources'] ?? [];
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Shared stack map') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('What breaks together') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Database, Redis, and queue bindings shared by multiple sites on this host.') }}</p>
        </div>
    </div>

    @if ($resources === [])
        <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
            {{ __('No shared database or cache bindings detected between sites. Each app may still compete for CPU and memory.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($resources as $resource)
                <li class="px-6 py-5 sm:px-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-sage">{{ ucfirst((string) ($resource['type'] ?? 'service')) }}</p>
                            <h3 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $resource['label'] }}</h3>
                            <p class="mt-1 text-sm text-brand-moss">{{ $resource['restart_impact'] }}</p>
                        </div>
                        <span class="inline-flex shrink-0 rounded-full bg-brand-sage/15 px-2.5 py-1 text-xs font-semibold text-brand-forest ring-1 ring-brand-sage/25">
                            {{ trans_choice(':count site|:count sites', (int) ($resource['site_count'] ?? 0), ['count' => (int) ($resource['site_count'] ?? 0)]) }}
                        </span>
                    </div>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($resource['sites'] ?? [] as $site)
                            <li>
                                <a href="{{ $site['href'] }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ $site['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    @endif
</section>
