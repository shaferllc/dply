<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(17rem,20rem)] lg:gap-8" wire:poll.5s="pollProvisioningStatus">
    <section class="dply-card overflow-hidden min-w-0 lg:col-start-1 lg:row-start-1">
        <div class="flex flex-col gap-6 border-b border-brand-ink/10 px-5 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-8">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-indigo-700">{{ __('Edge deployment') }}</p>
                        @if ($edgeJourneyHasFailed)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                <x-heroicon-s-x-mark class="h-3 w-3" />
                                {{ __('Failed') }}
                            </span>
                        @elseif ($edgeJourneyIsDone)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                <x-heroicon-s-check class="h-3 w-3" />
                                {{ __('Live') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                {{ __('Building') }}
                            </span>
                        @endif
                    </div>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                        {{ __('Edge build (:done/:total)', ['done' => $edgeCompletedSteps, 'total' => $edgeTotalSteps]) }}
                    </h2>
                    <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                        @if ($edgeJourneyHasFailed)
                            {{ __('The Edge build hit an error. Review the failure details below, then retry — Dply will clone, build, and publish again.') }}
                        @else
                            {{ __('Dply is cloning your repository, running the build, and publishing static assets to the Edge CDN.') }}
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                    @if ($edgeJourneyHasFailed)
                        <button
                            type="button"
                            wire:click="retryProvisioning"
                            wire:loading.attr="disabled"
                            wire:target="retryProvisioning"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry build') }}</span>
                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying…') }}</span>
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="openCancelProvisioningModal"
                        wire:loading.attr="disabled"
                        wire:target="openCancelProvisioningModal"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-800 shadow-sm transition-colors hover:border-red-300 hover:bg-red-100 disabled:opacity-60"
                    >
                        <x-heroicon-o-x-circle class="h-4 w-4" />
                        {{ __('Cancel build') }}
                    </button>
                </div>
            </div>

            <div>
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <span class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                        <x-heroicon-m-cloud-arrow-up class="h-4 w-4 text-brand-moss" />
                        {{ __('Edge build') }}
                    </span>
                    <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total', ['done' => $edgeCompletedSteps, 'total' => $edgeTotalSteps]) }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <div class="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80">
                        <div class="h-full rounded-full {{ $edgeJourneyHasFailed ? 'bg-red-500' : 'bg-indigo-600' }} transition-[width] duration-300" style="width: {{ $edgeProgressPercent }}%"></div>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $edgeJourneyHasFailed ? 'text-red-700' : 'text-indigo-700' }}">{{ $edgeProgressPercent }}%</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
            @if ($edgeJourneyHasFailed)
                <div class="rounded-2xl border-2 border-red-300 bg-red-50/95 px-5 py-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                                    <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-semibold text-red-900 sm:text-lg">{{ __('Build failed at: :step', ['step' => $edgeCurrentLabel]) }}</p>
                                    @if ($edgeProvisioningError)
                                        <div class="mt-2 rounded-xl border border-red-300 bg-white/80 px-4 py-3">
                                            <div class="flex items-start justify-between gap-3">
                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-red-700">{{ __('Reason') }}</p>
                                                <button
                                                    type="button"
                                                    x-data="{ copied: false }"
                                                    x-on:click="navigator.clipboard.writeText(@js($edgeProvisioningError)); copied = true; setTimeout(() => copied = false, 1500)"
                                                    class="shrink-0 rounded-md border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700 hover:border-red-300 hover:bg-red-50"
                                                >
                                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                </button>
                                            </div>
                                            <p class="mt-1 break-words font-mono text-sm leading-6 text-red-900">{{ $edgeProvisioningError }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/95 to-white px-4 py-4 sm:px-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-7 w-7 animate-spin items-center justify-center rounded-full border-[3px] border-indigo-200 border-t-indigo-600" aria-hidden="true"></span>
                                <p class="text-base font-semibold text-brand-ink sm:text-lg">{{ $edgeCurrentLabel }}</p>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-brand-moss">
                                {{ __('This page updates live as the build moves through clone, build, and publish. Your site goes live once assets are on the Edge CDN.') }}
                            </p>
                            @if ($edgeProvisioningState === 'building')
                                <dl class="mt-4 grid gap-3 rounded-xl border border-brand-ink/10 bg-white/80 p-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                                        <dd class="mt-1 break-all font-mono text-xs text-brand-ink">{{ $edgeBuildCommand }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Output directory') }}</dt>
                                        <dd class="mt-1 font-mono text-xs text-brand-ink">{{ $edgeOutputDir }}</dd>
                                    </div>
                                </dl>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                <div class="flex items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Build steps') }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Clone, build, publish, and go live on Edge CDN.') }}</p>
                    </div>
                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                        {{ max(1, $edgeCurrentStepIndex + 1) }} / {{ $edgeTotalSteps }}
                    </span>
                </div>
                <ol class="divide-y divide-brand-ink/5">
                    @foreach ($edgeVisibleSteps as $key => $label)
                        @php
                            $loopIndex = array_search($key, $edgeStepKeys, true);
                            $isDone = ! $edgeJourneyHasFailed && $loopIndex !== false && $loopIndex < $edgeCurrentStepIndex;
                            $isCurrent = $key === $edgeProvisioningState;
                        @endphp
                        <li class="flex items-start gap-4 px-5 py-4 sm:px-6">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $isCurrent ? ($edgeJourneyHasFailed ? 'bg-red-600 text-white' : 'bg-indigo-600 text-white ring-4 ring-indigo-100') : ($isDone ? 'bg-emerald-600 text-white' : 'bg-white text-brand-mist ring-1 ring-brand-ink/10') }}">
                                @if ($isDone)
                                    <x-heroicon-s-check class="h-4 w-4" />
                                @elseif ($isCurrent && ! $edgeJourneyHasFailed)
                                    <span class="inline-flex h-3 w-3 animate-pulse rounded-full bg-white"></span>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium text-brand-ink">{{ $label }}</p>
                                    @if ($isCurrent && ! $edgeJourneyHasFailed)
                                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-800">{{ __('Live') }}</span>
                                    @elseif ($isDone)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm leading-6 {{ $isDone ? 'text-brand-forest' : 'text-brand-moss' }}">
                                    @if ($key === 'queued')
                                        {{ __('Clone the connected Git repository and prepare the build workspace.') }}
                                    @elseif ($key === 'building')
                                        {{ __('Run :command and collect output from :dir.', ['command' => $edgeBuildCommand, 'dir' => $edgeOutputDir]) }}
                                    @elseif ($key === 'publishing')
                                        {{ __('Upload build artifacts to R2 and update Edge routing.') }}
                                    @elseif ($key === 'live')
                                        {{ __('Site is served from the Edge CDN — open the live URL or site workspace.') }}
                                    @elseif ($isCurrent && ! $edgeJourneyHasFailed)
                                        {{ __('This is the active build step right now.') }}
                                    @elseif ($isDone)
                                        {{ __('Completed successfully.') }}
                                    @else
                                        {{ __('Runs automatically once the earlier steps finish.') }}
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    <aside class="w-full space-y-6 self-start lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:max-w-none">
        <section class="dply-card overflow-hidden p-5 sm:p-6">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge summary') }}</h3>
            <dl class="mt-4 flex flex-col gap-3 text-sm">
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="mt-0.5 font-semibold capitalize text-brand-ink">{{ $site->statusLabel() }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $edgeRepoLabel }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $edgeBuildCommand }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Output directory') }}</dt>
                    <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $edgeOutputDir }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Current step') }}</dt>
                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $edgeCurrentLabel }}</dd>
                </div>
                @if ($edgeLatestDeployment)
                    <div class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Deployment') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs capitalize text-brand-ink">{{ $edgeLatestDeployment->status }}</dd>
                    </div>
                @endif
            </dl>
            @if ($edgeLiveUrl)
                <div class="mt-5 rounded-2xl border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white px-4 py-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Live URL') }}</p>
                    <p class="mt-2 break-all font-mono text-xs text-emerald-950">{{ $edgeLiveUrl }}</p>
                    <p class="mt-2 text-xs leading-5 text-emerald-800/80">{{ __('Available once publish completes.') }}</p>
                </div>
            @endif
        </section>

        @can('delete', $site)
            <section class="dply-card overflow-hidden p-5 sm:p-6">
                <p class="text-xs leading-relaxed text-brand-moss">
                    {{ __('If the build is stuck or you want to abandon it, cancel to remove partial deployments and delete the Edge site.') }}
                </p>
            </section>
        @endcan
    </aside>
</div>
