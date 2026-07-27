{{-- Edge create flow — sticky companion summary (sibling of profile-shell form). --}}
@php
    $appLabel = trim((string) ($form->name ?? '')) !== '' ? $form->name : __('Untitled edge app');
    $repoLabel = trim((string) $repo) !== '' ? $repo : __('Repository (unset)');
    $buildCommand = trim((string) ($form->build_command ?? ''));
    $outputDir = trim((string) ($form->output_dir ?? ''));
    $runtimeMode = (string) ($form->runtime_mode ?? 'static');
    $runtimeLabel = match ($runtimeMode) {
        'hybrid' => __('Hybrid (static + origin)'),
        'ssr' => __('Worker-native SSR'),
        default => __('Static / SSG'),
    };
    $deliveryLabel = ($form->delivery_mode ?? 'managed') === 'byo'
        ? __('Your account (BYO)')
        : __('Dply-hosted');
@endphp
<aside class="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-3.5 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Deploy summary') }}</p>
            <p class="mt-1 truncate text-sm font-semibold text-brand-ink">{{ $appLabel }}</p>
        </div>
        <dl class="divide-y divide-brand-ink/8 text-sm dark:divide-brand-mist/15">
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Source') }}</dt>
                <dd class="min-w-0 text-end font-mono text-xs text-brand-ink dark:text-brand-cream">
                    <span class="block truncate">{{ $repoLabel }}</span>
                    @if (trim((string) $branch) !== '')
                        <span class="mt-0.5 block text-[11px] text-brand-moss">{{ __('Branch') }} {{ $branch }}</span>
                    @endif
                </dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Build') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">
                    <span class="block truncate font-mono font-normal">{{ $buildCommand !== '' ? $buildCommand : __('Detected / default') }}</span>
                    <span class="mt-0.5 block text-[11px] font-normal text-brand-moss">
                        {{ __('Output') }} {{ $outputDir !== '' ? $outputDir : 'dist' }}
                    </span>
                </dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Mode') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $runtimeLabel }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Hosting') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $deliveryLabel }}</dd>
            </div>
        </dl>
    </div>

    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-3.5 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Global delivery') }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('Git push to edge in minutes') }}</p>
        </div>
        <ol class="space-y-0 px-5 py-3.5">
            @foreach ([
                ['icon' => 'code-bracket', 'title' => __('Connect Git'), 'desc' => __('Point at your repo and production branch.')],
                ['icon' => 'cpu-chip', 'title' => __('Build static output'), 'desc' => __('dply runs your build and collects dist/out assets.')],
                ['icon' => 'globe-alt', 'title' => __('Publish globally'), 'desc' => __('HTTPS on the edge network with instant cache invalidation.')],
            ] as $step)
                <li class="relative flex gap-3 pb-3.5 last:pb-0">
                    @if (! $loop->last)
                        <span class="absolute start-[1.125rem] top-9 h-[calc(100%-1rem)] w-px bg-brand-ink/10 dark:bg-brand-mist/20" aria-hidden="true"></span>
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

    <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Works great with') }}</p>
        <div class="mt-2.5 flex flex-wrap gap-1.5">
            @foreach (['Vite', 'Next.js', 'Nuxt', 'Astro', 'React', 'Vue', 'Svelte', 'Hugo', 'Eleventy'] as $framework)
                <span class="inline-flex items-center rounded-lg border border-brand-ink/10 bg-brand-cream/60 px-2 py-0.5 text-[11px] font-semibold text-brand-forest dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-sage">
                    {{ $framework }}
                </span>
            @endforeach
        </div>
        <p class="mt-2.5 text-xs leading-relaxed text-brand-moss">{{ __('Static export and SSG — or hybrid with a Cloud origin for SSR. For long-lived apps, use Cloud.') }}</p>
    </div>

    <div class="rounded-2xl border border-brand-sage/25 bg-gradient-to-br from-brand-cream via-white to-brand-sand/30 p-4 shadow-sm dark:border-brand-sage/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Estimated cost') }}</p>
        <p class="mt-1.5 text-3xl font-semibold tracking-tight text-brand-ink">
            ${{ number_format($edgeFee, 2) }}<span class="text-base font-medium text-brand-moss">/mo</span>
        </p>
        <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
            @if ($edgeUsageBillingEnabled)
                {{ __(':fee/mo platform fee per live site. Each site includes :requests requests, :egress GB egress, and :storage GB storage per month — usage beyond that is billed by the unit.', [
                    'fee' => '$'.number_format($edgeFee, 2),
                    'requests' => number_format($edgeUsageRates['included_requests_per_site']),
                    'egress' => number_format($edgeUsageRates['included_egress_gb_per_site']),
                    'storage' => number_format($edgeUsageRates['included_r2_storage_gb_per_site'] ?? 1),
                ]) }}
            @else
                {{ __('Flat dply per-site fee once your edge app is live. Branch previews are free.') }}
            @endif
        </p>
        <x-docs-link doc-route="docs.markdown" doc-slug="edge-create" class="mt-3 !border-0 !bg-transparent !px-0 !py-0 !shadow-none text-xs font-semibold text-brand-sage transition-colors hover:text-brand-forest dark:hover:text-brand-gold">
            {{ __('Browse documentation') }}
            <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
        </x-docs-link>
    </div>
</aside>
