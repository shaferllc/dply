{{-- Lean Overview: status + URL + shortcuts. Detail lives in sidebar sections. --}}
@php
    $jumpLinks = [
        [
            'section' => 'edge-deploys',
            'label' => __('Deploys'),
            'hint' => __('History & rollback'),
            'icon' => 'heroicon-o-rocket-launch',
        ],
        [
            'section' => 'edge-build',
            'label' => __('Build'),
            'hint' => __('Command, output, hooks'),
            'icon' => 'heroicon-o-wrench-screwdriver',
        ],
        [
            'section' => 'edge-routing',
            'label' => __('Routing'),
            'hint' => __('Domains & path rules'),
            'icon' => 'heroicon-o-arrows-right-left',
        ],
        [
            'section' => 'edge-delivery',
            'label' => __('Delivery'),
            'hint' => __('Backend & CDN'),
            'icon' => 'heroicon-o-cloud',
        ],
        [
            'section' => 'edge-environment',
            'label' => __('Environment'),
            'hint' => __('Env vars'),
            'icon' => 'heroicon-o-command-line',
        ],
        [
            'section' => 'edge-traffic',
            'label' => __('Traffic'),
            'hint' => __('Requests & bandwidth'),
            'icon' => 'heroicon-o-signal',
        ],
    ];

    $recentDeployments = $edgeDeployments->take(3);
@endphp

<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    @include('livewire.sites.partials.edge.hero')

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    <section class="border-b border-brand-ink/10">
        <div class="px-5 py-3 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Manage') }}</p>
        </div>
        <div class="grid gap-px border-t border-brand-ink/10 bg-brand-ink/[0.07] sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($jumpLinks as $link)
                <a
                    href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => $link['section']]) }}"
                    wire:navigate
                    class="group flex items-start gap-3 bg-white px-5 py-4 transition hover:bg-brand-sand/25 sm:px-6 dark:bg-zinc-900/60 dark:hover:bg-zinc-900"
                >
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25 transition group-hover:bg-brand-sage/25">
                        <x-dynamic-component :component="$link['icon']" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-1 text-sm font-semibold text-brand-ink">
                            {{ $link['label'] }}
                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist opacity-0 transition group-hover:opacity-100" />
                        </span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ $link['hint'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    @if ($recentDeployments->isNotEmpty())
        <section>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent deploys') }}</p>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys']) }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">
                    {{ __('View all') }}
                </a>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($recentDeployments as $deployment)
                    @php
                        $sha = $deployment->commit_sha ? substr((string) $deployment->commit_sha, 0, 7) : null;
                        $when = optional($deployment->published_at ?? $deployment->created_at)->diffForHumans();
                    @endphp
                    <li>
                        <a
                            href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}"
                            wire:navigate
                            class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm hover:bg-brand-sand/20 sm:px-6"
                        >
                            <span class="inline-flex min-w-0 items-center gap-2">
                                <span class="rounded-full bg-brand-sand/50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ str_replace('_', ' ', (string) $deployment->status) }}
                                </span>
                                @if ($sha)
                                    <span class="font-mono text-xs text-brand-ink">{{ $sha }}</span>
                                @endif
                                @if (filled($deployment->branch))
                                    <span class="truncate text-xs text-brand-moss">{{ $deployment->branch }}</span>
                                @endif
                            </span>
                            <span class="text-xs text-brand-mist">{{ $when }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
