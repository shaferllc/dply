@php
    $statusTone = match (true) {
        $live => 'live',
        $cancelled => 'cancelled',
        $failed => 'failed',
        $deployPaused => 'paused',
        default => 'active',
    };
@endphp
<div @if ($shouldPoll) wire:poll.3s @endif>
    <div @class([
        'dply-page-shell py-8 sm:py-10' => ! $embedded,
        'min-w-0' => $embedded,
    ])>
        @unless ($embedded)
            <x-breadcrumb-trail
                :items="[
                    ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                    ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'sparkles'],
                    ['label' => $site->name, 'icon' => 'bolt'],
                ]"
                wrapperClass="mb-5"
            />
        @endunless

        <section @class([
            'min-w-0',
            'dply-card overflow-hidden p-0' => ! $embedded,
        ])>
            {{-- Identity + progress --}}
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6 sm:py-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span @class([
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1',
                            'bg-brand-forest/15 text-brand-forest ring-brand-forest/25' => $statusTone === 'live',
                            'bg-rose-100 text-rose-700 ring-rose-200' => $statusTone === 'failed',
                            'bg-amber-100 text-amber-800 ring-amber-200' => $statusTone === 'cancelled' || $statusTone === 'paused',
                            'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $statusTone === 'active',
                        ])>
                            @if ($statusTone === 'live')
                                <x-heroicon-o-check-circle class="h-6 w-6" aria-hidden="true" />
                            @elseif ($statusTone === 'failed')
                                <x-heroicon-o-exclamation-triangle class="h-6 w-6" aria-hidden="true" />
                            @elseif ($statusTone === 'paused')
                                <x-heroicon-o-credit-card class="h-6 w-6" aria-hidden="true" />
                            @elseif ($statusTone === 'cancelled')
                                <x-heroicon-o-pause-circle class="h-6 w-6" aria-hidden="true" />
                            @else
                                <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                            @endif
                        </span>
                        <div class="min-w-0">
                            @unless ($embedded)
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Serverless deploy') }}</p>
                            @endunless
                            <h1 @class([
                                'font-semibold tracking-tight text-brand-ink',
                                'mt-0.5 text-xl sm:text-2xl' => ! $embedded,
                                'text-base' => $embedded,
                            ])>{{ $title }}</h1>
                            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-moss">
                                @if ($repoLabel !== '')
                                    <span class="inline-flex min-w-0 items-center gap-1.5" title="{{ $site->git_repository_url }}">
                                        <x-heroicon-o-code-bracket class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        <span class="truncate font-medium text-brand-ink">{{ $repoLabel }}</span>
                                    </span>
                                @endif
                                @if ($site->git_branch)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-white/70 px-1.5 py-0.5 font-mono text-xs text-brand-moss ring-1 ring-brand-ink/10">
                                        <x-heroicon-o-map class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ $site->git_branch }}
                                    </span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                        <span @class([
                            'inline-flex items-center justify-center gap-1.5 self-start rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide ring-1 sm:self-end',
                            'bg-emerald-100 text-emerald-800 ring-emerald-200' => $statusTone === 'live',
                            'bg-rose-100 text-rose-800 ring-rose-200' => $statusTone === 'failed',
                            'bg-amber-100 text-amber-900 ring-amber-200' => $statusTone === 'cancelled' || $statusTone === 'paused',
                            'bg-sky-100 text-sky-800 ring-sky-200' => $statusTone === 'active',
                        ])>
                            @if ($statusTone === 'active')
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" />
                            @elseif ($statusTone === 'live')
                                <x-heroicon-s-check class="h-3.5 w-3.5" />
                            @elseif ($statusTone === 'failed')
                                <x-heroicon-s-x-mark class="h-3.5 w-3.5" />
                            @else
                                <x-heroicon-o-pause class="h-3.5 w-3.5" />
                            @endif
                            {{ $headline }}
                        </span>
                        <p class="text-sm text-brand-moss sm:text-right">
                            <span class="font-semibold tabular-nums text-brand-ink">{{ $percent }}%</span>
                            {{-- A paused deploy never started, so a running clock
                                 would read as progress that isn't happening. --}}
                            @unless ($deployPaused)
                                <span class="text-brand-moss/70">·</span>
                                {{ $elapsedLabel }}
                                <span class="font-mono tabular-nums">{{ $elapsedHuman }}</span>
                            @endunless
                        </p>
                    </div>
                </div>

                <div class="mt-4 h-2.5 w-full overflow-hidden rounded-full bg-brand-ink/10">
                    <div @class([
                        'h-full rounded-full transition-all duration-500',
                        'bg-brand-forest' => $statusTone === 'live',
                        'bg-rose-500' => $statusTone === 'failed',
                        'bg-amber-400' => $statusTone === 'cancelled' || $statusTone === 'paused',
                        'bg-brand-gold' => $statusTone === 'active',
                    ]) style="width: {{ max($percent, $statusTone === 'active' ? 4 : 0) }}%"></div>
                </div>

                {{-- Primary actions in the header so retry is always obvious --}}
                @if ($namespaceState === 'failed' || $deployState === 'failed' || $live || $cancellable || $deployPaused)
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        @if ($cancellable)
                            <button type="button" wire:click="openCancelModal"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-semibold text-rose-800 transition hover:border-rose-300 hover:bg-rose-100">
                                <x-heroicon-o-x-circle class="h-4 w-4" aria-hidden="true" />
                                {{ __('Cancel deploy') }}
                            </button>
                        @endif

                        @if ($namespaceState === 'failed')
                            <button type="button" wire:click="retryProvision" wire:loading.attr="disabled" wire:target="retryProvision"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-70">
                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="retryProvision" aria-hidden="true" />
                                <span wire:loading.remove wire:target="retryProvision">{{ __('Retry provisioning') }}</span>
                                <span wire:loading wire:target="retryProvision">{{ __('Retrying…') }}</span>
                            </button>

                            {{-- A namespace that won't provision is the other
                                 dead end — retrying may never help, so offer the
                                 way out alongside the retry. --}}
                            @can('delete', $site)
                                <button type="button" wire:click="openDeleteFunctionModal"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50">
                                    <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Delete function') }}
                                </button>
                            @endcan
                        @elseif ($deployState === 'failed')
                            <button type="button" wire:click="retryDeploy" wire:loading.attr="disabled" wire:target="retryDeploy"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-70">
                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="retryDeploy" aria-hidden="true" />
                                <span wire:loading.remove wire:target="retryDeploy">{{ __('Retry deploy') }}</span>
                                <span wire:loading wire:target="retryDeploy">{{ __('Retrying…') }}</span>
                            </button>

                            {{-- Discard the failed run itself, so a function that
                                 failed on its first deploy isn't stuck showing a
                                 red journey forever. --}}
                            <button type="button" wire:click="openDeleteDeploymentModal" wire:loading.attr="disabled" wire:target="openDeleteDeploymentModal"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 disabled:opacity-70">
                                <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                {{ __('Delete failed run') }}
                            </button>
                        @endif

                        @if ($live)
                            <button type="button" wire:click="redeploy" wire:loading.attr="disabled" wire:target="redeploy"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-70">
                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="redeploy" aria-hidden="true" />
                                <span wire:loading.remove wire:target="redeploy">{{ __('Redeploy') }}</span>
                                <span wire:loading wire:target="redeploy">{{ __('Starting…') }}</span>
                            </button>
                        @endif

                        @if ($live && $actionUrl)
                            <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/50">
                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                                {{ __('Open app') }}
                            </a>
                        @endif

                        @unless ($embedded)
                            <a href="{{ \App\Support\Serverless\ServerlessWorkspaceUrl::show($site) }}" wire:navigate
                               class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/50">
                                {{ __('Open workspace') }}
                            </a>
                        @endunless
                    </div>
                @endif
            </div>

            {{-- Billing-pause callout. The namespace is already provisioned and
                 paid for; only the build/deploy is withheld — say so plainly and
                 point at the one action that unblocks it. --}}
            @if ($deployPaused)
                <div class="border-b border-brand-ink/10 bg-amber-50/80 px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Deploys are paused for this organization') }}</p>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Your function was created and its namespace is ready, but the build was not started because this organization can\'t run billed work right now. Add a payment method and deploy — nothing here is lost.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start">
                            @if ($billingUrl)
                                <a href="{{ $billingUrl }}" wire:navigate
                                   class="inline-flex items-center gap-1.5 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                                    <x-heroicon-o-credit-card class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Add a payment method') }}
                                </a>
                            @endif
                            {{-- The other way out: don't pay, don't keep the
                                 namespace. Never gated on billing. --}}
                            @can('delete', $site)
                                <button type="button" wire:click="openDeleteFunctionModal"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50">
                                    <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Delete function') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </div>
            @endif

            {{-- Failure / cancelled / live callout --}}
            @if ($failed || $cancelled || $live)
                <div @class([
                    'border-b border-brand-ink/10 px-5 py-4 sm:px-6',
                    'bg-rose-50/80' => $failed && ! $cancelled,
                    'bg-amber-50/80' => $cancelled,
                    'bg-emerald-50/50' => $live && ! $failed && ! $cancelled,
                ])>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">
                                @if ($live && ! $failed)
                                    {{ __('Your function is live') }}
                                @elseif ($cancelled)
                                    {{ __('Deploy cancelled') }}
                                @else
                                    {{ __('Deploy failed') }}
                                    @if ($failedStepLabel !== '')
                                        <span class="font-normal text-brand-moss">· {{ $failedStepLabel }}</span>
                                    @endif
                                @endif
                            </p>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                @if ($live && ! $failed)
                                    {{ __('It\'s deployed and answering requests.') }}
                                @elseif ($cancelled)
                                    {{ __('Nothing was rolled back — retry when you are ready.') }}
                                @elseif ($errorSummary !== '')
                                    <span class="font-mono text-[13px] leading-snug text-rose-900">{{ $errorSummary }}</span>
                                @else
                                    {{ __('This app is not live. Review the log, fix the issue, then retry.') }}
                                @endif
                            </p>
                        </div>
                        @if (($failed || $cancelled) && $errorSummary !== '')
                            <button
                                type="button"
                                x-data="{ copied: false }"
                                x-on:click="navigator.clipboard.writeText(@js($errorSummary)); copied = true; setTimeout(() => copied = false, 1500)"
                                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-800 transition hover:bg-rose-50"
                            >
                                <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" />
                                <span x-show="!copied">{{ __('Copy error') }}</span>
                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Body: stages | details + log --}}
            <div class="grid lg:grid-cols-12 lg:divide-x lg:divide-brand-ink/10">
                <div class="lg:col-span-7">
                    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Deploy stages') }}</h2>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Namespace, build, and go-live — updates every few seconds.') }}</p>
                    </div>

                    <ol class="divide-y divide-brand-ink/8">
                        @foreach ($stages as $stage)
                            <li class="relative px-5 py-4 sm:px-6">
                                @unless ($loop->last)
                                    <span aria-hidden="true" @class([
                                        'absolute left-[2.15rem] top-11 bottom-0 w-px sm:left-[2.4rem]',
                                        'bg-brand-forest/40' => $stage['state'] === 'done',
                                        'bg-brand-ink/10' => $stage['state'] !== 'done',
                                    ])></span>
                                @endunless

                                <div class="flex items-start gap-3">
                                    <span @class([
                                        'relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white',
                                        'bg-brand-forest text-white' => $stage['state'] === 'done',
                                        'bg-brand-gold text-brand-ink ring-brand-gold/30' => $stage['state'] === 'active',
                                        'bg-rose-500 text-white' => $stage['state'] === 'failed',
                                        'bg-amber-400 text-amber-950' => $stage['state'] === 'blocked',
                                        'bg-white text-brand-mist ring-1 ring-brand-ink/15 !ring-1' => $stage['state'] === 'pending',
                                    ])>
                                        @if ($stage['state'] === 'done')
                                            <x-heroicon-s-check class="h-4 w-4" />
                                        @elseif ($stage['state'] === 'active')
                                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" />
                                        @elseif ($stage['state'] === 'failed')
                                            <x-heroicon-s-x-mark class="h-4 w-4" />
                                        @elseif ($stage['state'] === 'blocked')
                                            <x-heroicon-s-pause class="h-4 w-4" />
                                        @else
                                            {{ $loop->iteration }}
                                        @endif
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p @class([
                                                'text-sm font-semibold',
                                                'text-brand-ink' => $stage['state'] !== 'pending',
                                                'text-brand-moss/55' => $stage['state'] === 'pending',
                                            ])>{{ $stage['label'] }}</p>
                                            @if ($stage['state'] === 'active')
                                                <span class="rounded-full bg-brand-sage/20 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('In progress') }}</span>
                                            @elseif ($stage['state'] === 'failed')
                                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-rose-800">{{ __('Failed') }}</span>
                                            @elseif ($stage['state'] === 'done')
                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                                            @elseif ($stage['state'] === 'blocked')
                                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900">{{ __('Paused') }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $stage['detail'] }}</p>

                                        @if ($stage['key'] === 'deploy' && count($deploySteps) > 0)
                                            <ul class="mt-3 space-y-0 overflow-hidden rounded-xl border border-brand-ink/10 bg-brand-cream/30">
                                                @foreach ($deploySteps as $sub)
                                                    <li @class([
                                                        'flex items-center gap-2.5 border-b border-brand-ink/8 px-3 py-2 last:border-b-0',
                                                        'bg-rose-50/80' => $sub['state'] === 'failed',
                                                        'bg-brand-sage/10' => $sub['state'] === 'active',
                                                    ])>
                                                        <span @class([
                                                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                                                            'bg-brand-forest text-white' => $sub['state'] === 'done',
                                                            'bg-brand-gold text-brand-ink' => $sub['state'] === 'active',
                                                            'bg-rose-500 text-white' => $sub['state'] === 'failed',
                                                            'bg-brand-ink/10 text-brand-moss/50' => $sub['state'] === 'pending',
                                                        ])>
                                                            @if ($sub['state'] === 'done')
                                                                <x-heroicon-s-check class="h-3 w-3" />
                                                            @elseif ($sub['state'] === 'active')
                                                                <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                                            @elseif ($sub['state'] === 'failed')
                                                                <x-heroicon-s-x-mark class="h-3 w-3" />
                                                            @else
                                                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                                            @endif
                                                        </span>
                                                        <span @class([
                                                            'min-w-0 flex-1 text-sm',
                                                            'font-medium text-brand-ink' => $sub['state'] !== 'pending',
                                                            'text-brand-moss/50' => $sub['state'] === 'pending',
                                                        ])>
                                                            {{ $sub['label'] }}
                                                            @if ($sub['detail'] !== '')
                                                                <span class="mt-0.5 block truncate font-mono text-xs font-normal text-brand-moss/70">{{ $sub['detail'] }}</span>
                                                            @endif
                                                        </span>
                                                        @if ($sub['duration'] !== '')
                                                            <span class="shrink-0 font-mono text-xs text-brand-moss/55">{{ $sub['duration'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="border-t border-brand-ink/10 lg:col-span-5 lg:border-t-0">
                    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-semibold text-brand-ink">{{ __('App details') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Resolved as the deploy progresses.') }}</p>
                            </div>
                            @if ($deployDuration !== '')
                                <span class="shrink-0 rounded-md bg-brand-sand/40 px-2 py-1 font-mono text-xs text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ $deployDuration }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-px bg-brand-ink/5">
                        @foreach ($facts as $fact)
                            <div class="bg-white px-4 py-3">
                                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $fact['label'] }}</dt>
                                <dd @class([
                                    'mt-1 break-all text-sm',
                                    'font-mono' => $fact['mono'] ?? false,
                                    'font-medium text-brand-ink' => $fact['value'] !== null,
                                    'text-brand-moss/40' => $fact['value'] === null,
                                ])>{{ $fact['value'] ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($actionUrl)
                        <div class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invocation URL') }}</p>
                            <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                               class="mt-1 block break-all font-mono text-sm text-brand-forest hover:underline">{{ $actionUrl }}</a>
                        </div>
                    @endif

                    @if (trim($log) !== '')
                        <div class="border-t border-brand-ink/10">
                            <div class="flex items-center justify-between gap-2 px-5 py-3 sm:px-6">
                                <div>
                                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Deploy log') }}</h2>
                                    @if ($deployStartedAt)
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Started') }} {{ $deployStartedAt->diffForHumans() }}</p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    x-on:click="navigator.clipboard.writeText(@js($log)); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink transition hover:border-brand-sage/40"
                                >
                                    <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" />
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            </div>
                            <div
                                class="bg-brand-ink px-4 py-3 sm:px-5"
                                x-data="{
                                    pinned: true,
                                    onScroll() {
                                        const el = $refs.logPre;
                                        this.pinned = (el.scrollHeight - el.scrollTop - el.clientHeight) < 24;
                                    },
                                    stick() {
                                        if (this.pinned && $refs.logPre) {
                                            $refs.logPre.scrollTop = $refs.logPre.scrollHeight;
                                        }
                                    },
                                }"
                                x-init="$nextTick(() => stick())"
                                x-effect="stick()"
                            >
                                <pre
                                    x-ref="logPre"
                                    @scroll="onScroll()"
                                    class="max-h-[min(28rem,50vh)] overflow-auto font-mono text-xs leading-relaxed text-brand-cream/95 whitespace-pre-wrap break-all lg:max-h-[min(36rem,60vh)]"
                                >{{ $log }}</pre>
                            </div>
                        </div>
                    @else
                        <div class="border-t border-brand-ink/10 px-5 py-8 text-center sm:px-6">
                            <x-heroicon-o-command-line class="mx-auto h-8 w-8 text-brand-sage/50" aria-hidden="true" />
                            <p class="mt-2 text-sm font-medium text-brand-ink">{{ __('Waiting for build output') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Clone, adapter, and install logs appear here once the worker starts.') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    </div>

    {{-- Cancel confirmation — in-app modal (no browser confirm) --}}
    @if ($confirmingCancel)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="serverless-cancel-title">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeCancelModal"></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                    <h3 id="serverless-cancel-title" class="text-base font-semibold text-brand-ink">{{ __('Cancel this deploy?') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Stops at the next step boundary — in-flight steps finish first. Completed work is not rolled back.') }}
                    </p>
                </div>
                <div class="flex justify-end gap-2 px-5 py-4">
                    <button type="button" wire:click="closeCancelModal"
                            class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                        {{ __('Keep deploying') }}
                    </button>
                    <button type="button" wire:click="cancelDeploy" wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-xl bg-rose-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-70">
                        {{ __('Cancel deploy') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete-failed-run confirmation — same in-app modal shape as cancel --}}
    @if ($confirmingDeleteDeployment)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="serverless-delete-deploy-title">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDeleteDeploymentModal"></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                    <h3 id="serverless-delete-deploy-title" class="text-base font-semibold text-brand-ink">{{ __('Delete this failed run?') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('The run and its log are removed from history. The function itself is untouched — nothing is undeployed.') }}
                    </p>
                </div>
                <div class="flex justify-end gap-2 px-5 py-4">
                    <button type="button" wire:click="closeDeleteDeploymentModal"
                            class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                        {{ __('Keep it') }}
                    </button>
                    <button type="button" wire:click="deleteFailedDeployment" wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-xl bg-rose-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-70">
                        {{ __('Delete run') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Destructive: removes the function AND its namespace. Type-to-confirm,
         matching the site-removal flow elsewhere. --}}
    @if ($confirmingDeleteFunction)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="serverless-delete-fn-title">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeDeleteFunctionModal"></div>
            <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl">
                <div class="border-b border-brand-ink/10 bg-rose-50/70 px-5 py-4">
                    <h3 id="serverless-delete-fn-title" class="text-base font-semibold text-brand-ink">{{ __('Delete this function?') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Removes the function, its deploy history, and its DigitalOcean Functions namespace. This cannot be undone.') }}
                    </p>
                </div>
                <div class="px-5 py-4">
                    <label for="serverless-delete-fn-name" class="block text-sm font-medium text-brand-ink">
                        {{ __('Type :name to confirm', ['name' => $site->name]) }}
                    </label>
                    <input id="serverless-delete-fn-name" type="text" wire:model="deleteFunctionConfirmName"
                           autocomplete="off" autofocus
                           class="mt-1.5 w-full rounded-xl border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage" />
                    @error('deleteFunctionConfirmName')
                        <p class="mt-1.5 text-sm text-rose-700">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex justify-end gap-2 border-t border-brand-ink/10 px-5 py-4">
                    <button type="button" wire:click="closeDeleteFunctionModal"
                            class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="deleteFunction" wire:loading.attr="disabled" wire:target="deleteFunction"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-70">
                        <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="deleteFunction" aria-hidden="true" />
                        <span wire:loading.remove wire:target="deleteFunction">{{ __('Delete function') }}</span>
                        <span wire:loading wire:target="deleteFunction">{{ __('Deleting…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
