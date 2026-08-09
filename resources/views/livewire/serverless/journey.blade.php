<div @if ($shouldPoll) wire:poll.3s @endif>
    {{-- Match Serverless create/index + other provision journeys: max-w-7xl.
         Embedded on Deployments keeps the same full-width chrome without page padding. --}}
    <div @class([
        'mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8' => ! $embedded,
        'min-w-0' => $embedded,
    ])>
        @unless ($embedded)
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'sparkles'],
                ['label' => $site->name, 'icon' => 'bolt'],
            ]" />
        @endunless

        <section @class([
            'dply-card min-w-0 overflow-hidden p-0',
            'mt-4' => ! $embedded,
        ])>
            {{-- Sand identity header — progress + status --}}
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy journey') }}</p>
                            <h1 class="mt-0.5 text-lg font-semibold tracking-tight text-brand-ink">{{ $title }}</h1>
                            <p class="mt-0.5 truncate text-sm text-brand-moss">
                                <span class="font-mono">{{ $site->git_repository_url }}</span>@if ($site->git_branch)<span class="text-brand-moss/60"> · {{ $site->git_branch }}</span>@endif
                            </p>
                        </div>
                    </div>
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide ring-1',
                        'bg-brand-forest/15 text-brand-forest ring-brand-forest/20' => $live,
                        'bg-rose-100 text-rose-800 ring-rose-200' => $failed,
                        'bg-sky-100 text-sky-800 ring-sky-200' => ! $live && ! $failed,
                    ])>
                        @unless ($live || $failed)
                            <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                        @endunless
                        @if ($live)
                            <x-heroicon-s-check class="h-3 w-3" />
                        @elseif ($failed)
                            <x-heroicon-s-x-mark class="h-3 w-3" />
                        @endif
                        {{ $headline }}
                    </span>
                </div>

                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs font-medium text-brand-moss">
                        <span>{{ $percent }}% {{ __('complete') }}</span>
                        <span class="tabular-nums">{{ $elapsedLabel }} {{ $elapsedHuman }}</span>
                    </div>
                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-brand-ink/10">
                        <div @class([
                            'h-full rounded-full transition-all duration-500',
                            'bg-brand-forest' => $live,
                            'bg-rose-400' => $failed,
                            'bg-brand-gold' => ! $live && ! $failed,
                        ]) style="width: {{ max($percent, 2) }}%"></div>
                    </div>
                </div>
            </div>

            @php
                $banner = match (true) {
                    $live => [
                        'icon' => 'heroicon-o-check-circle',
                        'title' => __('Your app is live'),
                        'detail' => __('It\'s deployed and answering requests.'),
                        'ring' => 'border-brand-forest/25', 'wash' => 'bg-brand-forest/5',
                        'badge' => 'bg-brand-forest/15 text-brand-forest',
                    ],
                    $cancelled => [
                        'icon' => 'heroicon-o-pause-circle',
                        'title' => __('Deploy cancelled'),
                        'detail' => __('Nothing was rolled back — retry when you are ready.'),
                        'ring' => 'border-brand-gold/40', 'wash' => 'bg-brand-gold/10',
                        'badge' => 'bg-brand-gold/25 text-brand-ink',
                    ],
                    $failed => [
                        'icon' => 'heroicon-o-exclamation-triangle',
                        'title' => __('Deploy failed'),
                        'detail' => __('This app is not live. Review the log below, fix the issue, then retry.'),
                        'ring' => 'border-rose-200', 'wash' => 'bg-rose-50',
                        'badge' => 'bg-rose-100 text-rose-700',
                    ],
                    default => null,
                };
            @endphp
            @if ($banner)
                <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                    <div class="flex items-center gap-3 rounded-xl border {{ $banner['ring'] }} {{ $banner['wash'] }} px-3.5 py-2.5">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $banner['badge'] }}">
                            <x-dynamic-component :component="$banner['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ $banner['title'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $banner['detail'] }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stage timeline --}}
            <ol class="divide-y divide-brand-ink/8">
                @foreach ($stages as $stage)
                    <li class="relative flex items-start gap-3 px-5 py-3 sm:px-6">
                        @unless ($loop->last)
                            {{-- Center under the 28px status icon (px-5/sm:px-6 + half icon). --}}
                            <span aria-hidden="true" @class([
                                'absolute left-[33px] top-9 bottom-0 w-0.5 sm:left-[37px]',
                                'bg-brand-forest/35' => $stage['state'] === 'done',
                                'bg-brand-ink/10' => $stage['state'] !== 'done',
                            ])></span>
                        @endunless

                        <span @class([
                            'relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white',
                            'bg-brand-forest text-white' => $stage['state'] === 'done',
                            'bg-brand-gold text-brand-ink' => $stage['state'] === 'active',
                            'bg-rose-500 text-white' => $stage['state'] === 'failed',
                            'bg-brand-ink/5 text-brand-moss/50' => $stage['state'] === 'pending',
                        ])>
                            @if ($stage['state'] === 'done')
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                            @elseif ($stage['state'] === 'active')
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
                                </svg>
                            @elseif ($stage['state'] === 'failed')
                                &times;
                            @else
                                &bull;
                            @endif
                        </span>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <p @class([
                                'text-sm font-semibold',
                                'text-brand-ink' => $stage['state'] !== 'pending',
                                'text-brand-moss/50' => $stage['state'] === 'pending',
                            ])>{{ $stage['label'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $stage['detail'] }}</p>

                            @if ($stage['key'] === 'deploy' && count($deploySteps) > 0)
                                <ul class="mt-2 space-y-1.5 rounded-lg bg-brand-ink/[0.03] px-2.5 py-2">
                                    @foreach ($deploySteps as $sub)
                                        <li class="flex items-center gap-2 text-xs">
                                            <span @class([
                                                'flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold',
                                                'bg-brand-forest text-white' => $sub['state'] === 'done',
                                                'bg-brand-gold text-brand-ink' => $sub['state'] === 'active',
                                                'bg-rose-500 text-white' => $sub['state'] === 'failed',
                                                'bg-brand-ink/10 text-brand-moss/50' => $sub['state'] === 'pending',
                                            ])>
                                                @if ($sub['state'] === 'done')
                                                    <svg class="h-2.5 w-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                                @elseif ($sub['state'] === 'active')
                                                    <svg class="h-2.5 w-2.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
                                                    </svg>
                                                @elseif ($sub['state'] === 'failed')
                                                    &times;
                                                @else
                                                    &bull;
                                                @endif
                                            </span>
                                            <span @class([
                                                'font-medium',
                                                'text-brand-ink' => $sub['state'] !== 'pending',
                                                'text-brand-moss/50' => $sub['state'] === 'pending',
                                            ])>{{ $sub['label'] }}</span>
                                            @if ($sub['detail'] !== '')
                                                <span class="min-w-0 truncate font-mono text-brand-moss/60">{{ $sub['detail'] }}</span>
                                            @endif
                                            @if ($sub['duration'] !== '')
                                                <span class="ml-auto shrink-0 font-mono text-brand-moss/50">{{ $sub['duration'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            {{-- Retry / cancel / next-step controls --}}
            @if ($namespaceState === 'failed' || $deployState === 'failed' || $live || $cancellable)
                <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
                    @if ($cancellable)
                        <button type="button" wire:click="openCancelModal"
                                class="inline-flex items-center rounded-lg border border-rose-200 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 hover:border-rose-300 hover:bg-rose-50">
                            {{ __('Cancel deploy') }}
                        </button>
                    @endif

                    @if ($namespaceState === 'failed')
                        <button type="button" wire:click="retryProvision" wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            {{ __('Retry provisioning') }}
                        </button>
                    @elseif ($deployState === 'failed')
                        <button type="button" wire:click="retryDeploy" wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            {{ __('Retry deploy') }}
                        </button>
                    @endif

                    @if ($live)
                        <button type="button" wire:click="redeploy" wire:loading.attr="disabled" wire:target="redeploy"
                                class="inline-flex items-center rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            <span wire:loading.remove wire:target="redeploy">{{ __('Redeploy') }}</span>
                            <span wire:loading wire:target="redeploy">{{ __('Starting…') }}</span>
                        </button>
                    @endif

                    @if ($live && $actionUrl)
                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                           class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Open app') }}
                        </a>
                    @endif

                    @unless ($embedded)
                        <a href="{{ route('sites.show', [$server->id, $site->id]) }}" wire:navigate
                           class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3.5 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Go to dashboard') }}
                        </a>
                    @endunless
                </div>
            @endif

            {{-- App details — same card width as progress + log --}}
            <div class="border-t border-brand-ink/10">
                <div class="flex items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
                    <x-icon-badge>
                        <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Details') }}</p>
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('App details') }}</h2>
                    </div>
                    @if ($deployDuration !== '')
                        <span class="ml-auto shrink-0 text-xs text-brand-moss">{{ __('Deploy took') }} <span class="font-mono">{{ $deployDuration }}</span></span>
                    @endif
                </div>
                <div class="px-5 py-4 sm:px-6">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($facts as $fact)
                            <div class="min-w-0">
                                <dt class="text-xs font-medium text-brand-moss/70">{{ $fact['label'] }}</dt>
                                <dd @class([
                                    'mt-0.5 break-all text-sm',
                                    'font-mono' => $fact['mono'] ?? false,
                                    'text-brand-ink font-medium' => $fact['value'] !== null,
                                    'text-brand-moss/40' => $fact['value'] === null,
                                ])>{{ $fact['value'] ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($actionUrl)
                        <div class="mt-3 border-t border-brand-ink/10 pt-3">
                            <dt class="text-xs font-medium text-brand-moss/70">{{ __('Invocation URL') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                                   class="break-all font-mono text-sm text-brand-forest hover:underline">{{ $actionUrl }}</a>
                            </dd>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Deploy log — full width of the outer card --}}
            @if (trim($log) !== '')
                <div class="border-t border-brand-ink/10">
                    <div class="flex items-center justify-between gap-3 bg-brand-sand/15 px-5 py-2.5 sm:px-6">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy log') }}</p>
                        @if ($deployStartedAt)
                            <p class="text-xs text-brand-moss/60">{{ __('Started') }} {{ $deployStartedAt->diffForHumans() }}</p>
                        @endif
                    </div>
                    <div class="bg-brand-ink px-5 py-3 sm:px-6">
                        <pre class="max-h-96 overflow-auto font-mono text-[11px] leading-relaxed text-brand-cream whitespace-pre-wrap break-all">{{ $log }}</pre>
                    </div>
                </div>
            @endif
        </section>
    </div>

    {{-- Cancel-deploy confirmation --}}
    @if ($confirmingCancel)
        <div class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeCancelModal"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="text-base font-bold text-brand-ink">{{ __('Cancel this deploy?') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('The deploy stops at the next step boundary — it cannot interrupt a step already in flight. Completed steps are not rolled back, and you can retry afterwards.') }}
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" wire:click="closeCancelModal"
                            class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                        {{ __('Keep deploying') }}
                    </button>
                    <button type="button" wire:click="cancelDeploy" wire:loading.attr="disabled"
                            class="inline-flex items-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-70">
                        {{ __('Cancel deploy') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
