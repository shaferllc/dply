{{-- App-install (scaffold) pipeline — a flow distinct from the bare-site
     provisioning journey. Rendered inside the site workspace shell by Show,
     and inside the legacy standalone page wrapper by ScaffoldJourney. Shared
     variables come from InteractsWithScaffoldJourney::scaffoldJourneyData(). --}}
<div class="space-y-8" @if ($isRunning) wire:poll.3s="pollScaffoldStatus" @endif>

    {{-- Header --}}
    <header class="rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/30 p-8 shadow-sm">
        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Installing app') }}</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ $site->name }}</h1>
        <p class="mt-2 text-sm text-brand-moss">{{ __('Watching the :framework install pipeline. This is separate from your site setup — the server, domains, and testing URL are already in place.', ['framework' => $scaffoldFramework]) }}</p>
    </header>

    {{-- Step list --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Pipeline') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Install steps') }}</h2>
            </div>
        </div>
        <div class="px-6 py-6 sm:px-7">
        <ol class="space-y-3">
            @foreach ($steps as $step)
                <li class="flex items-start gap-3">
                    <span @class([
                        'mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold',
                        'bg-brand-sage text-white' => $step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_COMPLETED,
                        'bg-brand-gold/30 text-brand-ink animate-pulse' => $step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_RUNNING,
                        'bg-rose-100 text-rose-700' => $step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_FAILED,
                        'bg-brand-mist/30 text-brand-mist' => $step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_PENDING,
                    ])>
                        @if ($step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_COMPLETED)
                            <x-heroicon-m-check class="h-4 w-4" />
                        @elseif ($step['state'] === \App\Services\Scaffold\ScaffoldStep::STATE_FAILED)
                            <x-heroicon-m-x-mark class="h-4 w-4" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-brand-ink">{{ $step['label'] ?? $step['key'] }}</p>
                        @if (! empty($step['error']))
                            <p class="mt-1 font-mono text-xs text-rose-700">{{ $step['error'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        </div>
    </section>

    {{-- Failed-state retry panel --}}
    @if ($isFailed)
        <section class="dply-card overflow-hidden border-rose-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
                <x-icon-badge tone="danger">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Failed') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Install failed') }}</h2>
                    @if ($failedStep)
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Stopped at step ":step". Retry will destroy server-side artifacts and start over.', ['step' => $failedStep['label'] ?? $failedStep['key']]) }}</p>
                    @endif
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Attempt :n of 3.', ['n' => $attemptCount]) }}</p>
                </div>
            </div>
            <div class="px-6 py-6 sm:px-7">
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($canRetry)
                            <button
                                wire:click="retryScaffold"
                                wire:loading.attr="disabled"
                                wire:target="retryScaffold"
                                class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                {{ __('Retry install') }}
                            </button>
                        @else
                            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="inline-flex h-10 items-center gap-2 rounded-xl border border-rose-300 bg-white px-5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50">
                                <x-heroicon-o-trash class="h-4 w-4" />
                                {{ __('Delete site and start fresh') }}
                            </a>
                        @endif
                    </div>
            </div>
        </section>
    @endif

    {{-- Success-state reveal-once panel --}}
    @if ($isCompleted)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Complete') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Install complete') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Your :framework install is up. The admin password is shown only once — save it now.', ['framework' => $scaffoldFramework]) }}</p>
                </div>
            </div>
            <div class="px-6 py-6 sm:px-7">
                    <dl class="space-y-3 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="font-semibold text-brand-ink">{{ __('Admin email:') }}</dt>
                            <dd class="font-mono text-brand-moss">{{ $site->meta['scaffold']['admin_email'] ?? '' }}</dd>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="font-semibold text-brand-ink">{{ __('Admin password:') }}</dt>
                            <dd>
                                @if ($scaffoldPasswordRevealed)
                                    <code class="rounded-lg bg-white px-3 py-1 font-mono text-sm text-brand-ink ring-1 ring-brand-ink/15">{{ decrypt($site->meta['scaffold']['admin_password']) }}</code>
                                @else
                                    <button
                                        wire:click="revealScaffoldPassword"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-eye class="h-4 w-4" />
                                        {{ __('Reveal password') }}
                                    </button>
                                    <span class="ml-2 text-xs text-brand-mist">{{ __('Admin/owner only.') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                    <x-input-error :messages="$errors->get('reveal')" class="mt-2" />

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="inline-flex h-10 items-center gap-2 rounded-xl bg-brand-ink px-5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                            {{ __('Open site dashboard') }}
                            <x-heroicon-o-arrow-right class="h-4 w-4" />
                        </a>
                    </div>
            </div>
        </section>
    @endif

    {{-- Running-state hint (no spinner — wire:poll already updates the rows) --}}
    @if ($isRunning)
        <p class="text-center text-xs text-brand-mist">{{ __('Auto-refreshing every few seconds. Safe to close this tab and come back later.') }}</p>
    @endif
</div>
