<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(17rem,20rem)] lg:gap-8" wire:poll.5s="pollProvisioningStatus">
                    {{-- Header card: matches server provision-journey hero --}}
                    <section class="dply-card overflow-hidden min-w-0 lg:col-start-1 lg:row-start-1">
                        <div class="flex flex-col gap-6 border-b border-brand-ink/10 px-5 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-8">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Site provisioning') }}</p>
                                        @if ($siteJourneyHasFailed)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                                <x-heroicon-s-x-mark class="h-3 w-3" />
                                                {{ __('Failed') }}
                                            </span>
                                        @elseif ($siteJourneyIsDone)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                <x-heroicon-s-check class="h-3 w-3" />
                                                {{ __('Ready') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                                {{ __('Live') }}
                                            </span>
                                        @endif
                                    </div>
                                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink sm:text-2xl">
                                        {{ __('Site setup (:done/:total)', ['done' => $siteCompletedSteps, 'total' => $siteTotalSteps]) }}
                                    </h2>
                                    <p class="mt-2 max-w-prose text-sm leading-relaxed text-brand-moss">
                                        @if ($siteJourneyHasFailed)
                                            {{ __('Provisioning hit an error. Review the failure details below, then retry — Dply re-runs only the steps that need it.') }}
                                        @else
                                            {{ __('Dply is writing the web server config, attaching the temporary testing URL, and watching for the first hostname that responds.') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                    @if ($siteJourneyHasFailed)
                                        <button
                                            type="button"
                                            wire:click="retryProvisioning"
                                            wire:loading.attr="disabled"
                                            wire:target="retryProvisioning"
                                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:border-brand-sage hover:text-brand-sage disabled:opacity-60"
                                        >
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry provisioning') }}</span>
                                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying…') }}</span>
                                        </button>
                                    @endif
                                    @unless ($site->usesEdgeRuntime())
                                        <div x-data="{ open: false, busy: false }">
                                            <button
                                                type="button"
                                                x-on:click="open = true"
                                                x-bind:disabled="busy"
                                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-900 shadow-sm transition-colors hover:border-amber-300 hover:bg-amber-100 disabled:opacity-60"
                                            >
                                                <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                                                <span x-show="!busy">{{ __('Restart fresh') }}</span>
                                                <span x-show="busy" class="inline-flex items-center gap-1.5">
                                                    <x-spinner size="sm" />
                                                    {{ __('Queuing…') }}
                                                </span>
                                            </button>

                                            <template x-teleport="body">
                                                <div
                                                    x-show="open"
                                                    x-cloak
                                                    class="fixed inset-0 isolate z-[100] overflow-y-auto"
                                                    role="dialog"
                                                    aria-modal="true"
                                                    aria-labelledby="restart-fresh-modal-title"
                                                    x-on:keydown.escape.window="open = false"
                                                >
                                                    <div class="fixed inset-0 z-0 bg-brand-ink/60 backdrop-blur-sm" x-on:click="open = false"></div>
                                                    <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                                                        <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-brand-ink/5">
                                                            <div class="flex items-start gap-4 px-6 py-5 sm:px-7">
                                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-50 ring-1 ring-amber-100">
                                                                    <x-heroicon-o-arrow-uturn-left class="h-5 w-5 text-amber-700" aria-hidden="true" />
                                                                </span>
                                                                <div class="flex-1 pt-0.5">
                                                                    <h2 id="restart-fresh-modal-title" class="text-base font-semibold text-brand-ink">{{ __('Restart provisioning from scratch?') }}</h2>
                                                                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                                                        {{ __('This removes the testing DNS record, any certificates issued so far, and web server configuration written for this site on the server, then runs the full install again. Domains and site settings in Dply are kept.') }}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div class="flex flex-col-reverse gap-2 border-t border-brand-sand/60 bg-brand-cream/30 px-6 py-4 sm:flex-row sm:justify-end sm:px-7">
                                                                <button
                                                                    type="button"
                                                                    x-on:click="open = false"
                                                                    x-bind:disabled="busy"
                                                                    class="inline-flex justify-center rounded-lg border border-brand-ink/10 bg-white px-4 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/30 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {{ __('Cancel') }}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    x-on:click="busy = true; $wire.call('restartProvisioningFresh').then(() => { busy = false; open = false; })"
                                                                    x-bind:disabled="busy"
                                                                    class="inline-flex min-w-[9rem] items-center justify-center gap-2 rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-800 focus:outline-none focus:ring-2 focus:ring-amber-500/40 disabled:cursor-not-allowed disabled:bg-amber-300"
                                                                >
                                                                    <span x-show="!busy" class="inline-flex items-center gap-2">
                                                                        <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                                                                        {{ __('Restart fresh') }}
                                                                    </span>
                                                                    <span x-show="busy" class="inline-flex items-center gap-2">
                                                                        <x-spinner size="sm" variant="white" />
                                                                        {{ __('Queuing…') }}
                                                                    </span>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    @endunless
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
                                        <x-heroicon-m-wrench-screwdriver class="h-4 w-4 text-brand-moss" />
                                        {{ __('Site setup') }}
                                    </span>
                                    <span class="text-sm tabular-nums text-brand-moss">{{ __(':done of :total', ['done' => $siteCompletedSteps, 'total' => $siteTotalSteps]) }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="h-2.5 min-w-0 flex-1 overflow-hidden rounded-full bg-brand-sand/80">
                                        <div class="h-full rounded-full {{ $siteJourneyHasFailed ? 'bg-red-500' : 'bg-sky-600' }} transition-[width] duration-300" style="width: {{ $siteProgressPercent }}%"></div>
                                    </div>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $siteJourneyHasFailed ? 'text-red-700' : 'text-sky-700' }}">{{ $siteProgressPercent }}%</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-6 px-5 py-6 sm:px-8 sm:py-8">
                            @if ($siteJourneyHasFailed)
                                <div class="rounded-2xl border-2 border-red-300 bg-red-50/95 px-5 py-5 shadow-sm">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-3">
                                                <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                                                    <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-base font-semibold text-red-900 sm:text-lg">{{ __('Provisioning failed at: :step', ['step' => $siteCurrentLabel]) }}</p>
                                                    @if ($provisioningError)
                                                        <div class="mt-2 rounded-xl border border-red-300 bg-white/80 px-4 py-3">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <p class="text-[11px] font-semibold uppercase tracking-wide text-red-700">{{ __('Reason') }}</p>
                                                                <button
                                                                    type="button"
                                                                    x-data="{ copied: false }"
                                                                    x-on:click="navigator.clipboard.writeText(@js($provisioningError)); copied = true; setTimeout(() => copied = false, 1500)"
                                                                    class="shrink-0 rounded-md border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-700 hover:border-red-300 hover:bg-red-50"
                                                                >
                                                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                                                </button>
                                                            </div>
                                                            <p class="mt-1 break-words font-mono text-sm leading-6 text-red-900">{{ $provisioningError }}</p>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-red-800">{{ __('Failed') }}</span>
                                    </div>
                                </div>
                            @else
                                <div class="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-sky-50/95 to-white px-4 py-4 sm:px-5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-3">
                                                <span class="inline-flex h-7 w-7 animate-spin items-center justify-center rounded-full border-[3px] border-sky-200 border-t-sky-600" aria-hidden="true"></span>
                                                <p class="text-base font-semibold text-brand-ink sm:text-lg">{{ $siteCurrentLabel }}</p>
                                            </div>
                                            <p class="mt-3 text-sm leading-6 text-brand-moss">
                                                {{ __('This page updates live as the installer moves through each step. The site is considered ready as soon as either the testing URL or the real domain responds.') }}
                                            </p>
                                            @if ($provisioningLog->isNotEmpty())
                                                {{-- wire:ignore.self keeps the browser-set `open` attribute across
                                                     wire:poll morphs (so a user-expanded panel stays open) while still
                                                     letting Livewire morph the children — the live transcript below. --}}
                                                <details wire:key="install-activity" wire:ignore.self class="mt-4 overflow-hidden rounded-xl border border-brand-ink/10 bg-slate-950 shadow-inner group" x-data>
                                                    <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-white/5 bg-slate-900/80 px-4 py-2.5">
                                                        <span class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                            <x-heroicon-o-chevron-right class="h-3.5 w-3.5 transition-transform group-open:rotate-90" />
                                                            {{ __('Install activity') }}
                                                        </span>
                                                        <span class="text-[11px] text-slate-500">{{ __('last :count entries', ['count' => min(8, $provisioningLog->count())]) }}</span>
                                                    </summary>
                                                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words px-4 py-3 font-mono text-[12px] leading-5 text-slate-200">{{ $provisioningTranscript }}</pre>
                                                </details>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Step timeline --}}
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10">
                                <div class="flex items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Provisioning steps') }}</p>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Compact install timeline — done, running, and what comes next.') }}</p>
                                    </div>
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ max(1, $currentStepIndex + 1) }} / {{ $siteTotalSteps }}
                                    </span>
                                </div>
                                <ol class="divide-y divide-brand-ink/5">
                                    @foreach ($siteVisibleSteps as $key => $label)
                                        @php
                                            $loopIndex = array_search($key, $stepKeys, true);
                                            $isDone = ! $siteJourneyHasFailed && $loopIndex !== false && $loopIndex < $currentStepIndex;
                                            $isCurrent = $key === $provisioningState;
                                        @endphp
                                        <li class="flex items-start gap-4 px-5 py-4 sm:px-6">
                                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $isCurrent ? ($siteJourneyHasFailed ? 'bg-red-600 text-white' : 'bg-sky-600 text-white ring-4 ring-sky-100') : ($isDone ? 'bg-emerald-600 text-white' : 'bg-white text-brand-mist ring-1 ring-brand-ink/10') }}">
                                                @if ($isDone)
                                                    <x-heroicon-s-check class="h-4 w-4" />
                                                @elseif ($isCurrent && ! $siteJourneyHasFailed)
                                                    <span class="inline-flex h-3 w-3 animate-pulse rounded-full bg-white"></span>
                                                @else
                                                    {{ $loop->iteration }}
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-medium text-brand-ink">{{ $label }}</p>
                                                    @if ($isCurrent && ! $siteJourneyHasFailed)
                                                        <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800">{{ __('Live') }}</span>
                                                    @elseif ($isDone)
                                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                                                    @endif
                                                </div>
                                                <p class="mt-1 text-sm leading-6 {{ $isDone ? 'text-brand-forest' : 'text-brand-moss' }}">
                                                    @if ($isCurrent && ! $siteJourneyHasFailed)
                                                        {{ __('This is the active install step right now.') }}
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

                    {{-- Right sidebar — mirrors server provision-journey's
                         <aside>: site summary + testing URL + DNS readiness.
                         Sticky on lg so it stays in view while the journey
                         scrolls. --}}
                    <aside class="w-full space-y-6 self-start lg:col-start-2 lg:row-start-1 lg:sticky lg:top-24 lg:max-w-none">
                        <section class="dply-card overflow-hidden">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Summary') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site summary') }}</h3>
                                </div>
                            </div>
                            <div class="px-6 py-6 sm:px-7">
                            <dl class="grid grid-cols-1 gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                                    <dd class="mt-0.5 font-semibold capitalize text-brand-ink">{{ $site->statusLabel() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Type') }}</dt>
                                    <dd class="mt-0.5 font-medium capitalize text-brand-ink">{{ $site->type->label() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Web server') }}</dt>
                                    <dd class="mt-0.5 font-medium capitalize text-brand-ink">{{ $site->webserver() }}</dd>
                                </div>
                                @if ($site->runtimeKey())
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Runtime') }}</dt>
                                        <dd class="mt-0.5 font-medium text-brand-ink">
                                            <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                                                <span class="font-mono text-brand-mist"> · {{ $site->runtimeVersion() }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if ($site->internal_port)
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Internal port') }}</dt>
                                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $site->internal_port }}</dd>
                                    </div>
                                @endif
                                @if (filled($site->build_command))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $site->build_command }}</dd>
                                    </div>
                                @endif
                                @if (filled($site->start_command))
                                    <div class="sm:col-span-2">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Start command') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $site->start_command }}</dd>
                                    </div>
                                @endif
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Primary domain') }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-xs font-medium text-brand-ink">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Current step') }}</dt>
                                    <dd class="mt-0.5 font-medium text-brand-ink">{{ $siteCurrentLabel }}</dd>
                                </div>
                            </dl>
                            @if ($targetUrl)
                                <div
                                    x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($targetUrl)); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                                    class="mt-5 rounded-2xl border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white px-4 py-4"
                                >
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Testing URL') }}</p>
                                    <div class="mt-2 flex min-w-0 items-center gap-1.5 font-mono text-xs text-emerald-950">
                                        <span
                                            class="block min-w-0 flex-1 overflow-x-auto whitespace-nowrap"
                                            title="{{ $targetUrl }}"
                                        >{{ $targetUrl }}</span>
                                        <a
                                            href="{{ $targetUrl }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title="{{ __('Open URL') }}"
                                            class="shrink-0 text-emerald-950/70 hover:text-emerald-700"
                                        >
                                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                                        </a>
                                        <button
                                            type="button"
                                            x-on:click.stop="copy()"
                                            :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy URL') }}'"
                                            class="shrink-0 text-emerald-950/70 hover:text-emerald-700"
                                        >
                                            <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" aria-hidden="true" />
                                            <x-heroicon-s-check x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" />
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-emerald-800/80">{{ __('Use this first while the customer domain catches up.') }}</p>
                                </div>
                            @endif
                            </div>
                        </section>

                        @if (($preflightActionableChecks ?? collect())->isNotEmpty())
                            <x-site-preflight-issues-panel :checks="$preflightActionableChecks" compact />
                        @endif

                        <section class="dply-card overflow-hidden">
                            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                                <x-icon-badge>
                                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                                </x-icon-badge>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('DNS readiness') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Either URL can finish setup') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Dply checks both URLs and moves on as soon as one responds.') }}</p>
                                </div>
                            </div>
                            <div class="px-6 py-6 sm:px-7">

                            @if (($testingHostnameMeta['status'] ?? null) === 'failed')
                                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                    <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                                    <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                                </div>
                            @endif

                            @if ($hostChecks->isNotEmpty())
                                <ul class="mt-4 space-y-2">
                                    @foreach ($hostChecks as $check)
                                        <li class="rounded-xl border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/70' }} p-3">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="break-all font-mono text-xs font-medium text-brand-ink">{{ $check['hostname'] }}</p>
                                                    <p class="mt-1 text-[11px] leading-snug {{ ($check['ok'] ?? false) ? 'text-emerald-800' : 'text-amber-900' }}">
                                                        {{ ($check['ok'] ?? false) ? __('Reachable — can finish the install.') : ($check['error'] ?? __('Not reachable yet.')) }}
                                                    </p>
                                                </div>
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                    {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                                </span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-white/60 p-3 text-xs text-brand-moss">
                                    {{ __('No hostname checks yet — Dply will start polling once the web server config is written.') }}
                                </p>
                            @endif
                            </div>
                        </section>

                        @can('delete', $site)
                            <section class="dply-card overflow-hidden p-5 sm:p-6">
                                <p class="text-xs leading-relaxed text-brand-moss">
                                    {{ __('If the install is stuck or you want to abandon it, cancel provisioning to remove the temporary DNS record and clean up the generated server config.') }}
                                </p>
                            </section>
                        @endcan
                    </aside>
                </div>
