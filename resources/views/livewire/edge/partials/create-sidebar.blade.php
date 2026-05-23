{{-- Edge create flow — sticky sidebar with delivery story, frameworks, and pricing. --}}
<aside class="space-y-5 lg:sticky lg:top-8 lg:self-start">
    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-4 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Global delivery') }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('Git push to edge in minutes') }}</p>
        </div>
        <ol class="space-y-0 px-5 py-4">
            @foreach ([
                ['icon' => 'code-bracket', 'title' => __('Connect Git'), 'desc' => __('Point at your repo and production branch.')],
                ['icon' => 'cpu-chip', 'title' => __('Build static output'), 'desc' => __('dply runs your build and collects dist/out assets.')],
                ['icon' => 'globe-alt', 'title' => __('Publish globally'), 'desc' => __('HTTPS on the edge network with instant cache invalidation.')],
            ] as $step)
                <li class="relative flex gap-3 pb-5 last:pb-0">
                    @if (! $loop->last)
                        <span class="absolute start-[1.125rem] top-10 h-[calc(100%-1.25rem)] w-px bg-brand-ink/10 dark:bg-brand-mist/20" aria-hidden="true"></span>
                    @endif
                    <span class="relative z-10 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/20 dark:bg-brand-sage/20 dark:text-brand-sage dark:ring-brand-sage/30">
                        @switch($step['icon'])
                            @case('code-bracket')
                                <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('cpu-chip')
                                <x-heroicon-o-cpu-chip class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-globe-alt class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <div class="min-w-0 pt-0.5">
                        <p class="text-sm font-semibold text-brand-ink">{{ $step['title'] }}</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $step['desc'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Works great with') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach (['Vite', 'Next.js', 'Nuxt', 'Astro', 'React', 'Vue', 'Svelte', 'Hugo', 'Eleventy'] as $framework)
                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-cream/60 px-2.5 py-1 text-[11px] font-semibold text-brand-forest dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-sage">
                    {{ $framework }}
                </span>
            @endforeach
        </div>
        <p class="mt-3 text-xs leading-relaxed text-brand-moss">{{ __('Static export and SSG only in v1 — configure static output in your framework, or use dply Cloud for server workloads.') }}</p>
    </div>

    <div class="rounded-2xl border border-brand-sage/25 bg-gradient-to-br from-brand-cream via-white to-brand-sand/30 p-5 shadow-sm dark:border-brand-sage/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Estimated cost') }}</p>
        <p class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink">
            ${{ number_format($edgeFee, 2) }}<span class="text-base font-medium text-brand-moss">/mo</span>
        </p>
        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
            @if ($edgeUsageBillingEnabled)
                {{ __(':fee/mo platform fee per live site, plus metered delivery for traffic above :requests requests and :egress GB egress per site.', [
                    'fee' => '$'.number_format($edgeFee, 2),
                    'requests' => number_format($edgeUsageRates['included_requests_per_site']),
                    'egress' => number_format($edgeUsageRates['included_egress_gb_per_site']),
                ]) }}
            @else
                {{ __('Flat dply per-site fee once your edge app is live. Branch previews are free.') }}
            @endif
        </p>
        <a href="{{ route('docs.index') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-sage transition-colors hover:text-brand-forest dark:hover:text-brand-gold">
            {{ __('Browse documentation') }}
            <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
        </a>
    </div>
</aside>
