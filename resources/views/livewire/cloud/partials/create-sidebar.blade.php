{{-- Cloud create flow — sticky sidebar with the deploy journey + live cost. --}}
<aside class="space-y-5 lg:sticky lg:top-8 lg:self-start">
    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-4 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Managed cloud') }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('From repo or image to live app') }}</p>
        </div>
        <ol class="space-y-0 px-5 py-4">
            @foreach ([
                ['icon' => 'code-bracket', 'title' => __('Point at source'), 'desc' => __('A GitHub repo or a pre-built container image — dply takes it from there.')],
                ['icon' => 'cpu-chip', 'title' => __('Build & ship'), 'desc' => __('Buildpack or Dockerfile build, then rollout to your cloud account.')],
                ['icon' => 'cloud-arrow-up', 'title' => __('Run with HTTPS'), 'desc' => __('Auto-TLS, autoscaling, health checks, alerts — managed for you.')],
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
                                <x-heroicon-o-cloud-arrow-up class="h-4 w-4" aria-hidden="true" />
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
            @foreach (['Laravel', 'Node.js', 'Python', 'Go', 'Ruby on Rails', 'Django', 'Next.js (SSR)', 'Docker'] as $framework)
                <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-brand-cream/60 px-2.5 py-1 text-[11px] font-semibold text-brand-forest dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-sage">
                    {{ $framework }}
                </span>
            @endforeach
        </div>
        <p class="mt-3 text-xs leading-relaxed text-brand-moss">{{ __('Server-side workloads with long-lived processes. For static sites and SSG, dply Edge is faster and cheaper.') }}</p>
    </div>

    <div class="rounded-2xl border border-brand-sage/25 bg-gradient-to-br from-brand-cream via-white to-brand-sand/30 p-5 shadow-sm dark:border-brand-sage/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        @php($resourceEstimate = $resourceEstimate ?? 0)
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Estimated cost') }}</p>
        <p class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink">
            ${{ number_format($cloudFee + $resourceEstimate, 2) }}<span class="text-base font-medium text-brand-moss">/mo</span>
        </p>
        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
            {{ __('Platform fee') }} <span class="font-mono text-brand-ink">${{ number_format($cloudFee, 2) }}</span>
            + {{ __('resources') }} <span class="font-mono text-brand-ink">${{ number_format($resourceEstimate, 2) }}</span>{{ __('/mo') }}.
        </p>
        <p class="mt-1 text-[11px] leading-relaxed text-brand-mist">{{ __('Resources = container, workers, and databases (compute scales with size + instances). Branch previews are free.') }}</p>

        @if (is_string($costPreview['error'] ?? null) && $costPreview['error'] !== '')
            <div class="mt-4 rounded-xl border border-rose-200/80 bg-rose-50/80 px-3 py-2 text-xs leading-relaxed text-rose-900 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
                <p class="font-semibold">{{ __('Spec rejected') }}</p>
                <p class="mt-0.5">{{ $costPreview['error'] }}</p>
            </div>
        @endif

        <button
            type="button"
            wire:click="recomputeCostPreview"
            wire:loading.attr="disabled"
            wire:target="recomputeCostPreview"
            class="mt-4 inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 disabled:opacity-50 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700"
        >
            <x-heroicon-o-calculator class="h-4 w-4" aria-hidden="true" />
            <span wire:loading.remove wire:target="recomputeCostPreview">{{ __('Re-estimate cost') }}</span>
            <span wire:loading wire:target="recomputeCostPreview">{{ __('Calling cloud…') }}</span>
        </button>
    </div>
</aside>
