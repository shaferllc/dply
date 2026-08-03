@php
    // Shared density classes — same compact header / body rhythm the Runtime
    // tab uses, so the workspace panels read as one system.
    $panelHead = 'flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6';
    $panelBody = 'px-5 py-4 sm:px-6';
    $panelIcon = 'h-4 w-4 shrink-0 text-brand-sage';
    $panelTitle = 'text-sm font-semibold text-brand-ink';
    $panelNote = 'mt-1 text-xs leading-relaxed text-brand-moss';
    $factCell = 'bg-white px-4 py-2.5 transition-colors hover:bg-brand-sand/[0.15] sm:px-5';
    $factLabel = 'text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist';
    $headLink = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40';
@endphp

{{-- Services-first: a live site with no app yet. Configure services here, then
     connect a repo — the bindings wire into the first deploy automatically.
     Stays outside the merged General card (see settings.blade.php). --}}
@if ($site->canRechooseApp() && ! ($generalTabSkipChooseApp ?? false))
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
    <section class="mb-5 overflow-hidden rounded-2xl border border-brand-sage/30 bg-gradient-to-br from-brand-sage/10 via-white to-white shadow-sm">
        <div class="px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-forest text-brand-cream shadow-sm">
                        <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold tracking-tight text-brand-ink">{{ __('Set up your app') }}</h2>
                        <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('This site is live and serving its splash page. Configure its services (database, cache, queue, env) below if you need them, then choose how to ship — your services wire into the first deploy automatically.') }}</p>
                    </div>
                </div>
                <a href="{{ $chooseAppUrl }}" wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                    <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                    {{ __('Set up your app') }}
                </a>
            </div>

            <div class="mt-3.5 grid gap-2 sm:grid-cols-3">
                @foreach ($setupOptions as $option)
                    <a href="{{ $chooseAppLink($option['app']) }}" wire:navigate
                        class="group flex items-start gap-2.5 rounded-xl border border-brand-ink/8 bg-white/80 p-2.5 shadow-sm ring-1 ring-brand-ink/[0.02] transition hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sage/12 text-brand-forest ring-1 ring-brand-sage/15 transition group-hover:bg-brand-sage/20">
                            <x-dynamic-component :component="$option['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold text-brand-ink">{{ $option['title'] }}</span>
                            <span class="mt-0.5 block text-[11px] leading-snug text-brand-moss">{{ $option['body'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@unless ($generalTabChooseAppOnly ?? false)
{{-- Read-only overview. Edit affordances live elsewhere:
     primary hostname → Routing > Domains (pencil on the row);
     everything else → Settings tab. The header badge doubles as the
     site-logo control (click the avatar for upload/pull/remove) — it used
     to be a full-width card above this one. --}}
<div class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="flex min-w-0 items-center gap-2.5">
            <livewire:sites.logo-menu :site="$site" :key="'overview-logo-menu-'.$site->id" />
            <div class="min-w-0">
                <h2 class="{{ $panelTitle }}">{{ $generalOverviewTitle }}</h2>
                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                    {{ __('Edit the primary hostname from Routing > Domains; everything else lives in Settings.') }}
                </p>
            </div>
        </div>
    </div>

    {{-- Compact fact grid: joined hairline tiles (gap-px trick — the tinted
         wrapper shows through 1px gaps as the grid lines), two per row, label
         over value. The long testing URL spans the full width; boolean states
         render as tinted pills. Mirrors the server hero facts card. --}}
    <div class="px-5 py-4 sm:px-6">
        <dl class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2">
            @if ($testingHostname !== '')
                @php $testingUrl = 'http://'.$testingHostname; @endphp
                <div
                    x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($testingUrl)); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
                    class="group flex items-center justify-between gap-3 {{ $factCell }} sm:col-span-2"
                >
                    <div class="min-w-0">
                        <dt class="{{ $factLabel }}">{{ $runtimeMode === 'vm' ? __('Testing URL') : __('Temporary hostname') }}</dt>
                        <dd class="mt-1 min-w-0">
                            <a href="{{ $testingUrl }}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex max-w-full items-center gap-1 truncate font-mono text-sm font-medium text-brand-ink decoration-brand-sage/40 underline-offset-4 hover:text-brand-forest hover:underline"
                                title="{{ $testingUrl }}">{{ $testingHostname }}<x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" /></a>
                        </dd>
                    </div>
                    <button type="button" x-on:click.stop="copy()" :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy URL') }}'"
                        class="shrink-0 rounded-lg border border-brand-ink/10 bg-white p-1.5 text-brand-mist shadow-sm transition hover:border-brand-ink/20 hover:text-brand-ink">
                        <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" aria-hidden="true" />
                        <x-heroicon-s-check x-show="copied" x-cloak class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                    </button>
                </div>
            @endif

            @unless ($site->isHeadless())
                <div class="group flex items-center justify-between gap-3 {{ $factCell }}">
                    <div class="min-w-0">
                        <dt class="{{ $factLabel }}">{{ $primaryHostnameLabel }}</dt>
                        <dd class="mt-1 min-w-0">
                            @if ($settings_primary_domain !== '')
                                <a href="https://{{ $settings_primary_domain }}" target="_blank" rel="noopener noreferrer"
                                    class="inline-flex max-w-full items-center gap-1 truncate font-mono text-sm font-medium text-brand-ink decoration-brand-sage/40 underline-offset-4 hover:text-brand-forest hover:underline"
                                    title="https://{{ $settings_primary_domain }}">{{ $settings_primary_domain }}<x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0 text-brand-mist" aria-hidden="true" /></a>
                            @else
                                <span class="font-mono text-sm font-medium text-brand-ink">—</span>
                            @endif
                        </dd>
                    </div>
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate
                        title="{{ __('Edit in Routing') }}"
                        class="shrink-0 rounded-lg border border-transparent p-1.5 text-brand-mist opacity-0 transition hover:border-brand-ink/15 hover:bg-white hover:text-brand-ink focus-visible:opacity-100 group-hover:opacity-100">
                        <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>
            @endunless

            <div class="group flex items-center justify-between gap-3 {{ $factCell }}">
                <div class="min-w-0">
                    <dt class="{{ $factLabel }}">{{ $documentRootLabel }}</dt>
                    <dd class="mt-1 truncate font-mono text-sm font-medium text-brand-ink" title="{{ $settings_document_root }}">{{ $settings_document_root !== '' ? $settings_document_root : '—' }}</dd>
                </div>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate
                    title="{{ __('Edit in Settings') }}"
                    class="shrink-0 rounded-lg border border-transparent p-1.5 text-brand-mist opacity-0 transition hover:border-brand-ink/15 hover:bg-white hover:text-brand-ink focus-visible:opacity-100 group-hover:opacity-100">
                    <x-heroicon-o-pencil-square class="h-4 w-4" aria-hidden="true" />
                </a>
            </div>

            @php
                // Half-width tiles rendered above the cards (domain + web dir);
                // used to span the final card across both columns when the
                // overall tile count is odd, so the grid never ends on a hole.
                $overviewTileOffset = ($site->isHeadless() ? 0 : 1) + 1;
            @endphp
            @foreach ($summaryCards as $card)
                @php
                    $cardValue = trim((string) $card['value']);
                    $cardValueLower = strtolower($cardValue);
                    $cardIsPositive = \Illuminate\Support\Str::contains($cardValueLower, ['active', 'enabled', 'running', 'ready', 'healthy', 'published']);
                    $cardIsNegative = \Illuminate\Support\Str::contains($cardValueLower, ['disabled', 'failed', 'inactive', 'error', 'down', 'not ', 'never', 'unhealthy']);
                    $cardIsPath = \Illuminate\Support\Str::startsWith($cardValue, '/');
                @endphp
                <div class="{{ $factCell }} @if ($loop->last && ($overviewTileOffset + $loop->iteration) % 2 !== 0) sm:col-span-2 @endif">
                    <dt class="{{ $factLabel }}">{{ $card['label'] }}</dt>
                    <dd class="mt-1 flex min-w-0 items-center">
                        @if ($cardIsPositive || $cardIsNegative)
                            <span class="inline-flex min-w-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none ring-1 ring-inset {{ $cardIsNegative ? 'bg-rose-50 text-rose-800 ring-rose-600/15' : 'bg-emerald-50 text-emerald-800 ring-emerald-600/15' }}">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $cardIsNegative ? 'bg-rose-500' : 'bg-emerald-500' }}" aria-hidden="true"></span>
                                <span class="truncate">{{ ucfirst($cardValue) }}</span>
                            </span>
                        @else
                            <span class="min-w-0 truncate {{ $cardIsPath ? 'font-mono' : '' }} text-sm font-medium text-brand-ink" title="{{ $cardValue }}">{{ $cardValue !== '' ? $cardValue : '—' }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>

{{-- Console-action banner sits BELOW the Overview card on General (it renders
     at the top of <main> on every other section). --}}
@include('livewire.sites.settings.partials._console-action-banner', ['embeddedBanner' => true])

{{-- Health. SSL state is deliberately absent here — it already renders as a
     tone-coded pill in the Overview grid above from the same
     currentSslSummary() source; the Certificates link covers the drill-in. --}}
<div class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-heroicon-o-chart-bar class="{{ $panelIcon }}" aria-hidden="true" />
                <h2 class="{{ $panelTitle }}">{{ __('Health at a glance') }}</h2>
            </div>
            <p class="{{ $panelNote }}">{{ __('Deploy, runtime, and preflight state. Detailed editors live on the dedicated tabs.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a href="{{ route('sites.deployments.index', [$server, $site]) }}" wire:navigate class="{{ $headLink }}">
                <x-heroicon-o-code-bracket-square class="h-3.5 w-3.5" />
                {{ __('Deployments') }}
            </a>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime']) }}" wire:navigate class="{{ $headLink }}">
                <x-heroicon-o-cube-transparent class="h-3.5 w-3.5" />
                {{ __('Runtime') }}
            </a>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'certificates']) }}" wire:navigate class="{{ $headLink }}">
                <x-heroicon-o-shield-check class="h-3.5 w-3.5" />
                {{ __('Certificates') }}
            </a>
        </div>
    </div>

    <div class="{{ $panelBody }}">
        <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
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
                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                    <dt class="{{ $factLabel }}">{{ __('Last deploy') }}</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                        <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $this->latestDeployment]) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize {{ $latestTone }} hover:opacity-90">
                            {{ $latestStatus }}
                        </a>
                        @if ($this->latestDeployment->started_at)
                            <span class="text-brand-mist">{{ $this->latestDeployment->started_at->diffForHumans(null, true) }}</span>
                        @endif
                        <a href="{{ route('sites.deployments.index', ['server' => $server, 'site' => $site]) }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('All deploys') }}</a>
                    </dd>
                </div>
            @endif

            {{-- Runtime + stack in one cell: "Php · 8.3" over "PHP (PHP-FPM)".
                 Stack used to repeat down in Site details. --}}
            <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                <dt class="{{ $factLabel }}">{{ __('Runtime') }}</dt>
                <dd class="mt-1 text-xs font-medium text-brand-ink">
                    @if ($site->runtimeKey())
                        <span class="capitalize">{{ $site->runtimeKey() }}</span>@if ($site->runtimeVersion())
                            <span class="font-mono text-brand-mist"> · {{ $site->runtimeVersion() }}</span>
                        @endif
                    @else
                        <span class="text-brand-mist">—</span>
                    @endif
                </dd>
                <dd class="text-[11px] text-brand-mist">{{ $site->type->label() }}</dd>
            </div>

            <div @class([
                'rounded-lg border px-3 py-2',
                'border-brand-ink/10 bg-brand-sand/15' => $preflightErrors->isEmpty() && $preflightWarnings->isEmpty(),
                'border-rose-200 bg-rose-50/40' => $preflightErrors->isNotEmpty(),
                'border-amber-200 bg-amber-50/40' => $preflightErrors->isEmpty() && $preflightWarnings->isNotEmpty(),
            ])>
                <dt class="{{ $factLabel }}">{{ __('Preflight') }}</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium">
                    @if (! ($preflightActive ?? false))
                        <span class="inline-flex items-center gap-1.5 text-brand-mist">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-mist/60"></span>
                            {{ __('Runs at first deploy') }}
                        </span>
                    @elseif ($preflightErrors->isEmpty() && $preflightWarnings->isEmpty())
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
                    @if ($preflightErrors->isNotEmpty() || $preflightWarnings->isNotEmpty())
                        <a href="#site-preflight-issues" class="font-medium text-brand-forest underline decoration-brand-sage/40 hover:decoration-brand-sage">{{ __('View and fix') }}</a>
                    @endif
                </dd>
            </div>
        </dl>

        @if (($preflightActionableChecks ?? collect())->isNotEmpty())
            <div class="mt-3">
                <x-site-preflight-issues-panel :checks="$preflightActionableChecks" compact />
            </div>
        @endif

        @if ($this->latestDeployment !== null && (string) $this->latestDeployment->status === 'failed')
            <div class="mt-3">
                <x-ops-copilot-callout :site="$site" compact :show="true" />
            </div>
        @endif

        @if (in_array($site->runtime, ['node', 'static'], true))
            <div class="mt-3 rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-3 py-2 text-xs text-brand-ink">
                <span class="font-semibold text-brand-forest">{{ __('Cloud-eligible') }}</span> —
                <span class="text-brand-moss">{{ __('this :runtime site can deploy globally on dply cloud — managed HTTPS, auto-scaling, no VM to babysit.', ['runtime' => $site->runtime]) }}</span>
                <a href="{{ route('cloud.create') }}" wire:navigate class="ml-1 font-medium text-brand-forest underline decoration-brand-sage/40 hover:decoration-brand-sage">{{ __('Deploy to dply cloud') }} →</a>
            </div>
        @endif
    </div>
</div>

{{-- Details. Stack lives in the Health "Runtime" cell above, not repeated here. --}}
<div class="border-b border-brand-ink/10">
    <div class="{{ $panelHead }}">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <x-heroicon-o-identification class="{{ $panelIcon }}" aria-hidden="true" />
                <h2 class="{{ $panelTitle }}">{{ $detailsTitle }}</h2>
            </div>
            <p class="{{ $panelNote }}">{{ $detailsDescription }}</p>
        </div>
    </div>

    <div class="{{ $panelBody }}">
        @php
            $disk = $this->diskUsage;
            $diskBytes = data_get($disk, 'bytes');
            $diskFiles = data_get($disk, 'files');
            $diskMeasuredAt = data_get($disk, 'measured_at');
            $diskVolumeTotal = data_get($disk, 'volume_total_bytes');
            $diskVolumeUsed = data_get($disk, 'volume_used_bytes');
            $diskVolumePct = is_numeric($diskVolumeTotal) && is_numeric($diskVolumeUsed) && $diskVolumeTotal > 0
                ? min(100, round($diskVolumeUsed / $diskVolumeTotal * 100, 1))
                : null;
            $detailCell = 'rounded-lg border border-brand-ink/10 bg-white px-3 py-2 shadow-sm';
        @endphp
        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {{-- Created at --}}
            <div class="{{ $detailCell }}">
                <dt class="flex items-center gap-1.5 {{ $factLabel }}">
                    <x-heroicon-o-calendar-days class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    {{ __('Created at') }}
                </dt>
                <dd class="mt-1 text-xs font-semibold text-brand-ink">
                    {{ $site->created_at?->format('M j, Y') ?? '—' }}
                    @if ($site->created_at)
                        <span class="font-normal text-brand-moss"> · {{ $site->created_at->format('H:i') }} · {{ $site->created_at->diffForHumans() }}</span>
                    @endif
                </dd>
            </div>

            {{-- Site ID (copyable) --}}
            <div class="{{ $detailCell }}">
                <dt class="flex items-center gap-1.5 {{ $factLabel }}">
                    <x-heroicon-o-hashtag class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    {{ __('Site ID') }}
                </dt>
                <dd class="mt-1 flex items-center gap-2">
                    <span class="break-all font-mono text-xs font-semibold text-brand-ink">{{ $site->id }}</span>
                    <button type="button"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js((string) $site->id)); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium text-brand-sage hover:bg-brand-sand/50"
                        :title="copied ? @js(__('Copied')) : @js(__('Copy site ID'))">
                        <span x-show="!copied" class="inline-flex items-center gap-1">
                            <x-heroicon-o-clipboard-document class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Copy') }}
                        </span>
                        <span x-show="copied" x-cloak class="inline-flex items-center gap-1 text-emerald-600">
                            <x-heroicon-o-check class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Copied') }}
                        </span>
                    </button>
                </dd>
            </div>

            {{-- Disk usage (measurable on VM hosts) --}}
            <div class="{{ $detailCell }} sm:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <dt class="flex items-center gap-1.5 {{ $factLabel }}">
                            <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            {{ __('Disk usage') }}
                        </dt>
                        @if (is_numeric($diskBytes))
                            <dd class="mt-1 text-xs font-semibold text-brand-ink">
                                {{ \Illuminate\Support\Number::fileSize((int) $diskBytes) }}
                                <span class="font-normal text-brand-moss">
                                    @if (is_numeric($diskFiles)) · {{ number_format((int) $diskFiles) }} {{ trans_choice('file|files', (int) $diskFiles) }}@endif
                                    @if ($diskMeasuredAt) · {{ __('measured :ago', ['ago' => \Illuminate\Support\Carbon::parse($diskMeasuredAt)->diffForHumans()]) }}@endif
                                </span>
                            </dd>
                        @else
                            <dd class="mt-1 text-xs font-medium text-brand-mist">
                                {{ __('Not recorded yet') }}
                                <span class="text-brand-moss"> · {{ $this->canMeasureDiskUsage ? __('Run a measurement to see this site’s footprint.') : __('Only available for VM-hosted sites.') }}</span>
                            </dd>
                        @endif
                    </div>
                    @if ($this->canMeasureDiskUsage)
                        <button type="button"
                            wire:click="measureDiskUsage"
                            wire:target="measureDiskUsage"
                            wire:loading.attr="disabled"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="measureDiskUsage" aria-hidden="true" />
                            <span wire:loading.remove wire:target="measureDiskUsage">{{ is_numeric($diskBytes) ? __('Refresh') : __('Measure') }}</span>
                            <span wire:loading wire:target="measureDiskUsage">{{ __('Measuring…') }}</span>
                        </button>
                    @endif
                </div>

                @if ($diskVolumePct !== null)
                    <div class="mt-2">
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-sand/60">
                            <div class="h-full rounded-full {{ $diskVolumePct >= 90 ? 'bg-rose-500' : ($diskVolumePct >= 75 ? 'bg-amber-500' : 'bg-brand-sage') }}" style="width: {{ $diskVolumePct }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-brand-mist">
                            {{ __(':pct% of volume used', ['pct' => rtrim(rtrim(number_format($diskVolumePct, 1), '0'), '.')]) }}
                            @if (is_numeric($diskVolumeTotal))
                                · {{ __(':used of :total', ['used' => \Illuminate\Support\Number::fileSize((int) $diskVolumeUsed), 'total' => \Illuminate\Support\Number::fileSize((int) $diskVolumeTotal)]) }}
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        </dl>
    </div>
</div>

@if (data_get($site->meta, 'notes'))
    <div class="border-b border-brand-ink/10">
        <div class="{{ $panelHead }}">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-pencil-square class="{{ $panelIcon }}" aria-hidden="true" />
                    <h2 class="{{ $panelTitle }}">{{ __('Site notes') }}</h2>
                </div>
            </div>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'settings']) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('Edit in Settings') }} →</a>
        </div>

        <div class="{{ $panelBody }}">
            <p class="whitespace-pre-wrap text-sm leading-relaxed text-brand-ink">{{ data_get($site->meta, 'notes') }}</p>
        </div>
    </div>
@endif

{{-- The General CLI snippet renders in settings.blade.php AFTER the
     recent-deployments block, so it always sits at the very bottom of the page. --}}
@endunless
