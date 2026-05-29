<div @if ($shouldPoll) wire:poll.3s @endif>
    {{-- Standalone page centres a readable column; embedded on the
         Deployments tab it keeps the same readable cap, just without the
         page padding/centring so it sits inside the tab. --}}
    <div @class([
        'max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8' => ! $embedded,
        'max-w-3xl' => $embedded,
    ])>
        @unless ($embedded)
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Serverless'), 'icon' => 'sparkles'],
                ['label' => $site->name, 'icon' => 'bolt'],
            ]" />
        @endunless

        <div class="dply-card overflow-hidden">
            {{-- Hero --}}
            <div class="p-6 sm:p-8 border-b border-brand-ink/10">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="text-xl font-bold text-brand-ink">{{ $title }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">
                            <span class="font-mono">{{ $site->git_repository_url }}</span>@if ($site->git_branch)<span class="text-brand-moss/60"> · {{ $site->git_branch }}</span>@endif
                        </p>
                    </div>
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
                        'bg-brand-forest/15 text-brand-forest' => $live,
                        'bg-rose-100 text-rose-700' => $failed,
                        'bg-brand-gold/20 text-brand-ink' => ! $live && ! $failed,
                    ])>
                        @unless ($live || $failed)
                            <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
                            </svg>
                        @endunless
                        {{ $headline }}
                    </span>
                </div>

                {{-- Progress bar --}}
                <div class="mt-5">
                    <div class="flex items-center justify-between text-xs font-medium text-brand-moss">
                        <span>{{ $percent }}% {{ __('complete') }}</span>
                        <span>{{ $elapsedLabel }} {{ $elapsedHuman }}</span>
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
                        'title' => __('Your function is live'),
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
                        'title' => __('Deploy stopped'),
                        'detail' => __('Review the log below, then retry the step that failed.'),
                        'ring' => 'border-rose-200', 'wash' => 'bg-rose-50',
                        'badge' => 'bg-rose-100 text-rose-700',
                    ],
                    default => null,
                };
            @endphp
            @if ($banner)
                <div class="px-6 pt-6 sm:px-8 sm:pt-7">
                    <div class="flex items-center gap-4 rounded-2xl border {{ $banner['ring'] }} {{ $banner['wash'] }} px-5 py-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $banner['badge'] }}">
                            <x-dynamic-component :component="$banner['icon']" class="h-6 w-6" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[15px] font-bold text-brand-ink">{{ $banner['title'] }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ $banner['detail'] }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stage timeline --}}
            <ol class="px-6 py-6 sm:px-8">
                @foreach ($stages as $stage)
                    <li class="relative flex items-start gap-4 pb-6 last:pb-0">
                        {{-- Connecting spine — runs from this stage's icon to the
                             next; tinted green once the stage is complete. --}}
                        @unless ($loop->last)
                            <span aria-hidden="true" @class([
                                'absolute left-[13px] top-7 bottom-0 w-0.5',
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
                                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
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
                                <ul class="mt-3 space-y-2 rounded-xl bg-brand-ink/[0.03] px-3 py-2.5">
                                    @foreach ($deploySteps as $sub)
                                        <li class="flex items-center gap-2.5 text-xs">
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
                                                <span class="truncate font-mono text-brand-moss/60">{{ $sub['detail'] }}</span>
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
                <div class="px-6 py-5 sm:px-8 border-t border-brand-ink/10 flex flex-wrap items-center gap-3">
                    @if ($cancellable)
                        <button type="button" wire:click="openCancelModal"
                                class="inline-flex items-center rounded-xl border-2 border-rose-200 bg-white px-5 py-2.5 text-sm font-semibold text-rose-700 hover:border-rose-300 hover:bg-rose-50">
                            {{ __('Cancel deploy') }}
                        </button>
                    @endif

                    @if ($namespaceState === 'failed')
                        <button type="button" wire:click="retryProvision" wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            {{ __('Retry provisioning') }}
                        </button>
                    @elseif ($deployState === 'failed')
                        <button type="button" wire:click="retryDeploy" wire:loading.attr="disabled"
                                class="inline-flex items-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            {{ __('Retry deploy') }}
                        </button>
                    @endif

                    @if ($live)
                        <button type="button" wire:click="redeploy" wire:loading.attr="disabled" wire:target="redeploy"
                                class="inline-flex items-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                            <span wire:loading.remove wire:target="redeploy">{{ __('Redeploy') }}</span>
                            <span wire:loading wire:target="redeploy">{{ __('Starting…') }}</span>
                        </button>
                    @endif

                    @if ($live && $actionUrl)
                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                           class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Open function') }}
                        </a>
                    @endif

                    @unless ($embedded)
                        <a href="{{ route('sites.show', [$server->id, $site->id]) }}" wire:navigate
                           class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                            {{ __('Go to dashboard') }}
                        </a>
                    @endunless
                </div>
            @endif
        </div>

        {{-- Function facts --}}
        <div class="dply-card overflow-hidden mt-6">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Details') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Function details') }}</h2>
                </div>
                @if ($deployDuration !== '')
                    <span class="ml-auto shrink-0 text-xs text-brand-moss">{{ __('Deploy took') }} <span class="font-mono">{{ $deployDuration }}</span></span>
                @endif
            </div>
            <div class="px-6 py-6 sm:px-7">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                @foreach ($facts as $fact)
                    <div class="min-w-0">
                        <dt class="text-xs font-medium text-brand-moss/70">{{ $fact['label'] }}</dt>
                        <dd @class([
                            'mt-0.5 truncate text-sm',
                            'font-mono' => $fact['mono'] ?? false,
                            'text-brand-ink font-medium' => $fact['value'] !== null,
                            'text-brand-moss/40' => $fact['value'] === null,
                        ])>{{ $fact['value'] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($actionUrl)
                <div class="mt-5 border-t border-brand-ink/10 pt-4">
                    <dt class="text-xs font-medium text-brand-moss/70">{{ __('Invocation URL') }}</dt>
                    <dd class="mt-1">
                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener"
                           class="break-all font-mono text-sm text-brand-forest hover:underline">{{ $actionUrl }}</a>
                    </dd>
                </div>
            @endif
            </div>
        </div>

        {{-- Deploy log --}}
        @if (trim($log) !== '')
            <div class="mt-6">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Deploy log') }}</p>
                    @if ($deployStartedAt)
                        <p class="text-xs text-brand-moss/60">{{ __('Started') }} {{ $deployStartedAt->diffForHumans() }}</p>
                    @endif
                </div>
                <pre class="max-h-96 overflow-auto rounded-xl bg-brand-ink p-4 text-xs leading-relaxed text-brand-cream">{{ $log }}</pre>
            </div>
        @endif
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
