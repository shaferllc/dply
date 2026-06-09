@php
    $containerMeta = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
    $stack = is_array($containerMeta['hybrid_edge_stack'] ?? null) ? $containerMeta['hybrid_edge_stack'] : [];
    $stackStatus = (string) ($stack['status'] ?? '');
    $edgeSiteId = is_string($stack['edge_site_id'] ?? null) ? $stack['edge_site_id'] : '';
    $edgeSite = $edgeSiteId !== '' ? \App\Models\Site::query()->with('server')->find($edgeSiteId) : null;
    $shouldPoll = ! in_array($stackStatus, ['complete', 'failed'], true);
@endphp

@if ($stack !== [])
    <section
        @if ($shouldPoll) wire:poll.5s="refreshHybridStackStatus" @endif
        class="rounded-2xl border border-indigo-200/80 bg-indigo-50/60 p-6 shadow-sm sm:p-7 dark:border-indigo-900/40 dark:bg-indigo-950/25"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-700 dark:text-indigo-300">{{ __('Hybrid Edge stack') }}</p>
                <h2 class="mt-1 text-base font-semibold text-brand-ink">
                    @if ($stackStatus === 'complete')
                        {{ __('Edge hybrid app ready') }}
                    @elseif ($stackStatus === 'failed')
                        {{ __('Hybrid stack failed') }}
                    @elseif ($stackStatus === 'edge_provisioning')
                        {{ __('Creating Edge hybrid app…') }}
                    @else
                        {{ __('Provisioning Cloud SSR origin…') }}
                    @endif
                </h2>
                <p class="mt-2 max-w-prose text-sm text-brand-moss">
                    @if ($stackStatus === 'complete' && $edgeSite)
                        {{ __('Static assets publish to Edge; dynamic routes proxy to this Cloud origin.') }}
                    @elseif ($stackStatus === 'failed')
                        {{ (string) ($stack['error'] ?? __('Something went wrong while linking the Edge app.')) }}
                    @elseif ($stackStatus === 'edge_provisioning')
                        {{ __('Step 2 of 2 — origin is live. dply is creating the Edge hybrid site and queueing the first build.') }}
                    @else
                        {{ __('Step 1 of 2 — waiting for the Cloud origin URL. The Edge hybrid app is created automatically when the origin is live.') }}
                    @endif
                </p>
            </div>
            @if ($stackStatus === 'awaiting_origin' || $stackStatus === 'edge_provisioning')
                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-800 dark:bg-sky-900/50 dark:text-sky-200">
                    <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" aria-hidden="true" />
                    {{ __('In progress') }}
                </span>
            @elseif ($stackStatus === 'complete')
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                    <x-heroicon-s-check class="h-4 w-4" aria-hidden="true" />
                    {{ __('Complete') }}
                </span>
            @elseif ($stackStatus === 'failed')
                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-800 dark:bg-rose-900/40 dark:text-rose-200">
                    <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                    {{ __('Failed') }}
                </span>
            @endif
        </div>

        @if ($stackStatus === 'complete' && $edgeSite)
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <a
                    href="{{ route('sites.show', ['server' => $edgeSite->server, 'site' => $edgeSite]) }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-forest px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-ink dark:bg-brand-sage dark:text-brand-ink"
                >
                    <x-heroicon-o-globe-alt class="h-4 w-4" aria-hidden="true" />
                    {{ __('Open Edge app') }}
                </a>
                @if ($edgeSite->edgeLiveUrl())
                    <span class="font-mono text-xs text-brand-moss">{{ $edgeSite->edgeLiveUrl() }}</span>
                @endif
            </div>
        @endif
    </section>
@endif
