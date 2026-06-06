{{-- Services-first: a live site with no app yet. Configure services here, then
     connect a repo — the bindings wire into the first deploy automatically. --}}
@if ($site->canRechooseApp())
    @php
        // Each option is a shortcut that deep-links into the picker pre-selected
        // (?app=<key>) so the click lands on the exact action. "Install an app"
        // is a category (many installers), so it opens the picker un-filtered.
        $chooseAppLink = fn (string $app = '') => route('sites.choose-app', array_filter([
            'server' => $server->id,
            'site' => $site->id,
            'app' => $app,
        ], fn ($v) => $v !== ''));
        $chooseAppUrl = $chooseAppLink();
        $setupOptions = [
            ['icon' => 'heroicon-o-sparkles', 'title' => __('Install an app'), 'body' => __('WordPress, Laravel, Statamic & more — set up for you.'), 'app' => ''],
            ['icon' => 'heroicon-o-code-bracket', 'title' => __('Connect a Git repo'), 'body' => __('Deploy an existing application from your repository.'), 'app' => 'git'],
            ['icon' => 'heroicon-o-minus-circle', 'title' => __('Start blank'), 'body' => __('Keep the splash page and decide later.'), 'app' => 'blank'],
        ];
    @endphp
    <section class="mb-6 overflow-hidden rounded-2xl border border-brand-sage/30 bg-gradient-to-br from-brand-sage/10 via-white to-white shadow-sm">
        <div class="px-6 py-6 sm:px-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-forest text-brand-cream shadow-sm">
                        <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Next step') }}</p>
                        <h2 class="mt-0.5 text-lg font-semibold tracking-tight text-brand-ink">{{ __('Set up your app') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This site is live and serving its splash page. Configure its services (database, cache, queue, env) below if you need them, then choose how to ship — your services wire into the first deploy automatically.') }}</p>
                    </div>
                </div>
                <a href="{{ $chooseAppUrl }}" wire:navigate
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                    <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                    {{ __('Set up your app') }}
                </a>
            </div>

            <div class="mt-5 grid gap-2.5 sm:grid-cols-3">
                @foreach ($setupOptions as $option)
                    <a href="{{ $chooseAppLink($option['app']) }}" wire:navigate
                        class="group flex items-start gap-3 rounded-xl border border-brand-ink/8 bg-white/80 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.02] transition hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage/12 text-brand-forest ring-1 ring-brand-sage/15 transition group-hover:bg-brand-sage/20">
                            <x-dynamic-component :component="$option['icon']" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-brand-ink">{{ $option['title'] }}</span>
                            <span class="mt-0.5 block text-xs leading-snug text-brand-moss">{{ $option['body'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@include('livewire.sites.settings.partials.logo')

{{-- Read-only overview. Edit affordances live elsewhere:
     primary hostname → Routing > Domains (pencil on the row);
     everything else → Settings tab. --}}
<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overview') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $generalOverviewTitle }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('At-a-glance summary. Edit the primary hostname from Routing > Domains; everything else lives in Settings.') }}
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        @if ($testingHostname !== '')
            @php
                $testingUrl = 'http://'.$testingHostname;
            @endphp
            <div
                x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($testingUrl)); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                class="mb-5 rounded-xl border border-brand-ink/10 bg-white p-4"
            >
                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $runtimeMode === 'vm' ? __('Testing URL') : __('Temporary hostname') }}</p>
                <div class="mt-2 flex min-w-0 items-center gap-1.5 font-mono text-sm text-brand-ink">
                    <span
                        class="block min-w-0 flex-1 overflow-x-auto whitespace-nowrap"
                        title="{{ $testingUrl }}"
                    >{{ $testingHostname }}</span>
                    <a
                        href="{{ $testingUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        title="{{ __('Open URL') }}"
                        class="shrink-0 text-brand-mist hover:text-brand-sage"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                    <button
                        type="button"
                        x-on:click.stop="copy()"
                        :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy URL') }}'"
                        class="shrink-0 text-brand-mist hover:text-brand-sage"
                    >
                        <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" aria-hidden="true" />
                        <x-heroicon-s-check x-show="copied" x-cloak class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                    </button>
                </div>
            </div>
        @endif
        <div class="grid gap-5">
                @unless ($site->isHeadless())
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $primaryHostnameLabel }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="break-all font-mono text-sm text-brand-ink">{{ $settings_primary_domain !== '' ? $settings_primary_domain : '—' }}</span>
                        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">
                            <x-heroicon-o-pencil-square class="h-3 w-3" />
                            {{ __('Edit in Routing') }}
                        </a>
                    </div>
                </div>
                @endunless

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ $documentRootLabel }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="break-all font-mono text-sm text-brand-ink">{{ $settings_document_root !== '' ? $settings_document_root : '—' }}</span>
                        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">
                            <x-heroicon-o-pencil-square class="h-3 w-3" />
                            {{ __('Edit in Settings') }}
                        </a>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm sm:grid-cols-2">
                    @foreach ($summaryCards as $card)
                        <div>
                            <dt class="text-brand-mist">{{ $card['label'] }}</dt>
                            <dd class="mt-1 break-all font-medium text-brand-ink">{{ $card['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Status') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Health at a glance') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('At-a-glance deploy, runtime, and certificate state. Detailed editors live on the dedicated tabs.') }}</p>
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a href="{{ route('sites.deployments.index', [$server, $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-code-bracket-square class="h-3.5 w-3.5" />
                {{ __('Deployments') }}
            </a>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-cube-transparent class="h-3.5 w-3.5" />
                {{ __('Runtime') }}
            </a>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'certificates']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-shield-check class="h-3.5 w-3.5" />
                {{ __('Certificates') }}
            </a>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($this->latestDeployment !== null)
                @php
                    // Tone-coded badge: failed deploys rose, running sky, success emerald.
                    // Tests assert the bg-rose-100 class for failed deploys so the badge
                    // colour is part of the contract, not just a decorative cue.
                    $latestStatus = (string) $this->latestDeployment->status;
                    $latestTone = match ($latestStatus) {
                        'failed' => 'bg-rose-100 text-rose-800',
                        'running' => 'bg-sky-100 text-sky-800',
                        'success' => 'bg-emerald-100 text-emerald-800',
                        default => 'bg-brand-sand/60 text-brand-ink',
                    };
                @endphp
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Last deploy') }}</dt>
                    <dd class="mt-2 text-sm font-medium text-brand-ink">
                        <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $this->latestDeployment]) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize {{ $latestTone }} hover:opacity-90">
                            {{ $latestStatus }}
                        </a>
                        @if ($this->latestDeployment->started_at)
                            <span class="ml-1 text-xs font-normal text-brand-mist">· {{ $this->latestDeployment->started_at->diffForHumans(null, true) }}</span>
                        @endif
                    </dd>
                    <dd class="mt-1 text-xs">
                        <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('All deploys') }}</a>
                    </dd>
                </div>
            @endif
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Runtime') }}</dt>
                <dd class="mt-2 text-sm font-medium text-brand-ink">
                    @if ($site->runtimeKey())
                        <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                            <span class="font-mono text-brand-mist"> · {{ $site->runtimeVersion() }}</span>
                        @endif
                    @else
                        <span class="text-brand-mist">—</span>
                    @endif
                </dd>
            </div>
            <div @class([
                'rounded-xl border p-4',
                'border-brand-ink/10 bg-brand-sand/15' => $preflightErrors->isEmpty() && $preflightWarnings->isEmpty(),
                'border-rose-200 bg-rose-50/40' => $preflightErrors->isNotEmpty(),
                'border-amber-200 bg-amber-50/40' => $preflightErrors->isEmpty() && $preflightWarnings->isNotEmpty(),
            ])>
                <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('Preflight') }}</dt>
                <dd class="mt-2 text-sm font-medium">
                    @if ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
                        <span class="inline-flex items-center gap-1.5 text-emerald-700">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                            {{ __('Ready') }}
                        </span>
                    @elseif ($preflightErrors->isNotEmpty())
                        <a href="#site-preflight-issues" class="inline-flex items-center gap-1.5 text-rose-700 hover:text-rose-900">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-rose-600"></span>
                            {{ trans_choice('{1} :count blocker|[2,*] :count blockers', $preflightErrors->count(), ['count' => $preflightErrors->count()]) }}
                        </a>
                    @else
                        <a href="#site-preflight-issues" class="inline-flex items-center gap-1.5 text-amber-700 hover:text-amber-900">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            {{ trans_choice('{1} :count warning|[2,*] :count warnings', $preflightWarnings->count(), ['count' => $preflightWarnings->count()]) }}
                        </a>
                    @endif
                </dd>
                @if ($preflightErrors->isNotEmpty() || $preflightWarnings->isNotEmpty())
                    <p class="mt-2 text-xs text-brand-moss">
                        <a href="#site-preflight-issues" class="font-medium text-brand-forest underline decoration-brand-sage/40 hover:decoration-brand-sage">{{ __('View and fix') }}</a>
                    </p>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-mist">{{ __('SSL') }}</dt>
                <dd class="mt-2 text-sm font-medium text-brand-ink">{{ $site->currentSslSummary() }}</dd>
            </div>
        </dl>

        @if (($preflightActionableChecks ?? collect())->isNotEmpty())
            <div class="mt-5">
                <x-site-preflight-issues-panel :checks="$preflightActionableChecks" compact />
            </div>
        @endif

        @if ($this->latestDeployment !== null && (string) $this->latestDeployment->status === 'failed')
            <div class="mt-5">
                <x-ops-copilot-callout :site="$site" compact :show="true" />
            </div>
        @endif

        @if (in_array($site->runtime, ['node', 'static'], true))
            <div class="mt-5 rounded-xl border border-brand-sage/30 bg-brand-sage/10 p-3 text-xs text-brand-ink">
                <span class="font-semibold text-brand-forest">{{ __('Cloud-eligible') }}</span> —
                <span class="text-brand-moss">{{ __('this :runtime site can deploy globally on dply cloud — managed HTTPS, auto-scaling, no VM to babysit.', ['runtime' => $site->runtime]) }}</span>
                <a href="{{ route('cloud.create') }}" wire:navigate class="ml-1 font-medium text-brand-forest underline decoration-brand-sage/40 hover:decoration-brand-sage">{{ __('Deploy to dply cloud') }} →</a>
            </div>
        @endif
    </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-identification class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Details') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $detailsTitle }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ $detailsDescription }}
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        @php
            $diskUsageBytes = data_get($site->meta, 'disk_usage.bytes');
        @endphp
            <dl class="grid grid-cols-1 gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-brand-mist">{{ __('Created at') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $site->created_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Site ID') }}</dt>
                    <dd class="mt-1 font-mono text-xs font-medium text-brand-ink">{{ $site->id }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Stack') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">{{ $site->type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-brand-mist">{{ __('Disk usage') }}</dt>
                    <dd class="mt-1 font-medium text-brand-ink">
                        {{ is_numeric($diskUsageBytes) ? \Illuminate\Support\Number::fileSize((int) $diskUsageBytes) : __('Not recorded yet') }}
                    </dd>
                </div>
            </dl>
        </div>
</section>

@if (data_get($site->meta, 'notes'))
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Notes') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site notes') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate class="font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Edit in Settings') }}</a>
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <p class="whitespace-pre-wrap text-sm leading-relaxed text-brand-ink">{{ data_get($site->meta, 'notes') }}</p>
        </div>
    </section>
@endif

<x-cli-snippet :commands="[
    ['label' => __('Print primary URL'), 'command' => 'dply sites:url '.$site->slug],
    ['label' => __('Diagnose site'), 'command' => 'dply sites:doctor '.$site->slug],
    ['label' => __('Rename site'), 'command' => 'dply sites:rename '.$site->slug.' --name=\'New name\' --slug=new-slug'],
    ['label' => __('Export full config'), 'command' => 'dply sites:export:config '.$site->slug.' --to=site.json'],
    ['label' => __('Export deploy manifest'), 'command' => 'dply sites:export:manifest '.$site->slug.' --to=manifest.json'],
    ['label' => __('List all sites'), 'command' => 'dply sites:list'],
]" />
