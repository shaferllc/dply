@php
    $totalConfigs = $configurations->count();
    $providersInUse = $metrics['providers'];
    $allProviders = $metrics['allProviders'];
    $hasBackupSearch = trim($search ?? '') !== '';

    $coverage = $metrics['coverage'];

    $coverageTone = match (true) {
        $metrics['dumpSchedules'] === 0 => 'text-brand-mist',
        $metrics['onServer'] === 0 => 'text-brand-sage',
        $metrics['shipping'] === 0 => 'text-brand-rust',
        default => 'text-brand-gold',
    };

    // r=52 on a 120 viewBox, same dial as the other Backups tabs.
    $dialCircumference = 326.726;
    $dialOffset = $dialCircumference * (1 - min(100, max(0, $coverage)) / 100);

    $activityMax = max(1, collect($activity)->max(fn ($day) => $day['completed'] + $day['failed']));
    $activityTotal = collect($activity)->sum(fn ($day) => $day['completed'] + $day['failed']);
    $activityFailed = collect($activity)->sum(fn ($day) => $day['failed']);

    $providerMix = $configurations
        ->groupBy('provider')
        ->map->count()
        ->sortDesc();

    $shellDescription = $organization
        ? __('External storage shared by everyone in :org and reusable across every server. Add the bucket or remote here, then pick it when creating a schedule on a server.', ['org' => $organization->name])
        : __('External storage shared by your organization and reusable across every server. Add the bucket or remote here, then pick it when creating a schedule on a server.');

    // Per-provider chip tone, mirroring the engine chips on webserver
    // templates so users get a quick visual read on storage type.
    $providerBadge = function (string $provider): string {
        return match ($provider) {
            's3', 'aws_s3' => 'border-amber-200 bg-amber-50 text-amber-900',
            'b2', 'backblaze' => 'border-red-200 bg-red-50 text-red-700',
            'r2', 'cloudflare', 'cloudflare_r2' => 'border-sky-200 bg-sky-50 text-sky-700',
            'spaces', 'digitalocean_spaces' => 'border-violet-200 bg-violet-50 text-violet-700',
            'gcs', 'google_cloud_storage' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'sftp', 'ssh' => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
            default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
        };
    };

    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $totalConfigs > 0;
@endphp

{{-- Storage moved out of settings and into the product it configures, so it
     wears the Backups chrome: workspace nav, Backups breadcrumb, subnav.
     See docs/adr/backups-as-a-product.md, decision 13. --}}
<div class="contents">
    @verbatim
        <style>
            @keyframes dply-bar-rise { from { transform: scaleY(0); } to { transform: scaleY(1); } }
            /* --dial-full is the full circumference, set inline on the arc. */
            @keyframes dply-dial-draw { from { stroke-dashoffset: var(--dial-full); } }
            .dply-bar { transform-origin: bottom; animation: dply-bar-rise .55s cubic-bezier(.16,1,.3,1) both; }
            .dply-dial { animation: dply-dial-draw 1s cubic-bezier(.16,1,.3,1) both; }
            @media (prefers-reduced-motion: reduce) {
                .dply-bar, .dply-dial { animation: none; }
            }
        </style>
    @endverbatim

    <x-livewire-validation-errors />

    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'href' => route('backups.overview'), 'icon' => 'archive-box'],
            ['label' => __('Storage'), 'icon' => 'cloud-arrow-up'],
        ]" />

    <x-profile-shell
        dense
        :title="__('Backup destinations')"
        :description="$shellDescription"
        icon="heroicon-o-cloud-arrow-up"
    >
        <x-slot:actions>
            <a href="{{ route('backups.overview') }}" wire:navigate class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                {{ __('Backups hub') }}
            </a>
            @if ($showShellAdd)
                <button
                    type="button"
                    wire:click="openDestinationModal"
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add destination') }}
                </button>
            @endif
        </x-slot:actions>

        <x-slot:stats>
            {{-- Same inverted console as the four type tabs, scoped to storage:
                 how many scheduled dumps actually leave the box, what providers
                 hold them, and two weeks of writes. --}}
            <div class="relative overflow-hidden bg-brand-ink text-brand-cream">
                <div class="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full bg-brand-sage/25 blur-3xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -left-24 -bottom-24 h-64 w-64 rounded-full bg-brand-gold/15 blur-3xl" aria-hidden="true"></div>

                <div class="relative grid gap-6 px-4 py-5 sm:px-6 sm:py-6 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,26rem)] lg:items-center lg:gap-8">
                    <div class="flex items-center gap-4 sm:gap-5">
                        <div class="relative shrink-0">
                            <svg viewBox="0 0 120 120" class="h-24 w-24 -rotate-90 sm:h-28 sm:w-28" aria-hidden="true">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" class="text-brand-cream/12" />
                                <circle
                                    cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="9" stroke-linecap="round"
                                    class="dply-dial {{ $coverageTone }}"
                                    stroke-dasharray="{{ $dialCircumference }}"
                                    stroke-dashoffset="{{ $dialOffset }}"
                                    style="--dial-full: {{ $dialCircumference }}"
                                />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="font-mono text-2xl font-semibold tabular-nums leading-none text-brand-cream sm:text-[28px]">{{ $coverage }}<span class="text-base text-brand-cream/50">%</span></span>
                                <span class="mt-1 text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-cream/45">{{ __('shipped') }}</span>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Dumps leaving the box') }}</p>
                            <p class="mt-1.5 text-base font-semibold leading-snug tracking-tight text-brand-cream sm:text-lg">
                                @if ($metrics['dumpSchedules'] === 0)
                                    {{ __('No database schedules yet.') }}
                                @elseif ($metrics['onServer'] === 0)
                                    {{ __('Every dump ships to storage you own.') }}
                                @else
                                    {{ trans_choice(':count schedule keeps its dump on the server|:count schedules keep their dumps on the server', $metrics['onServer'], ['count' => $metrics['onServer']]) }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-brand-cream/55">
                                {{ __(':shipping of :total database schedules · :storage landed here', [
                                    'shipping' => $metrics['shipping'],
                                    'total' => $metrics['dumpSchedules'],
                                    'storage' => $metrics['storage'],
                                ]) }}
                            </p>
                        </div>
                    </div>

                    {{-- Which providers hold the bytes. --}}
                    <div class="min-w-0 lg:border-l lg:border-brand-cream/10 lg:pl-8">
                        <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Providers') }}</p>
                        @if ($providerMix->isEmpty())
                            <p class="mt-2.5 text-xs text-brand-cream/55">
                                {{ __('No destination configured. Scheduled dumps stay on the server that made them.') }}
                            </p>
                        @else
                            <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                @foreach ($providerMix as $provider => $count)
                                    <li class="inline-flex items-center gap-2 rounded-full bg-brand-cream/[0.07] px-2.5 py-1 ring-1 ring-brand-cream/12">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                        <span class="text-xs text-brand-cream/85">{{ \App\Models\BackupConfiguration::labelForProvider($provider) }}</span>
                                        <span class="font-mono text-xs font-semibold tabular-nums text-brand-cream">{{ $count }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-2 text-2xs leading-relaxed text-brand-cream/40">
                            {{ __(':used of :all supported providers in use · shared by everyone in :org.', [
                                'used' => $providersInUse,
                                'all' => $allProviders,
                                'org' => $organization?->name ?? __('your organization'),
                            ]) }}
                        </p>
                    </div>

                    {{-- Two weeks of writes, as an actual shape --}}
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-cream/45">{{ __('Writes · 14 days') }}</p>
                            <p class="flex items-center gap-3 text-2xs text-brand-cream/50">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2 w-2 rounded-[2px] bg-brand-sage" aria-hidden="true"></span>{{ __('completed') }}
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2 w-2 rounded-[2px] bg-brand-rust" aria-hidden="true"></span>{{ __('failed') }}
                                </span>
                            </p>
                        </div>

                        <div class="mt-3 flex items-end gap-[3px] sm:gap-1">
                            @foreach ($activity as $day)
                                @php
                                    $dayTotal = $day['completed'] + $day['failed'];
                                    // Floor at 14% so a single write is still a visible
                                    // bar next to a busy day.
                                    $barPercent = $dayTotal > 0
                                        ? max(14, (int) round($dayTotal / $activityMax * 100))
                                        : 0;
                                    $failedPercent = $dayTotal > 0
                                        ? (int) round($day['failed'] / $dayTotal * 100)
                                        : 0;
                                    $dayLabel = $day['date']->format('D M j').' — '.
                                        trans_choice(':count write|:count writes', $dayTotal, ['count' => $dayTotal]).
                                        ($day['failed'] > 0 ? ', '.__(':count failed', ['count' => $day['failed']]) : '');
                                @endphp
                                <div class="group flex min-w-0 flex-1 flex-col items-center gap-1.5" title="{{ $dayLabel }}">
                                    <div class="flex h-16 w-full items-end sm:h-20">
                                        @if ($dayTotal > 0)
                                            <div
                                                class="dply-bar flex w-full flex-col justify-end overflow-hidden rounded-[4px] shadow-sm shadow-black/20 transition-opacity group-hover:opacity-80"
                                                style="height: {{ $barPercent }}%; animation-delay: {{ $loop->index * 35 }}ms"
                                            >
                                                @if ($day['failed'] > 0)
                                                    <div class="w-full bg-gradient-to-t from-brand-rust to-brand-copper" style="height: {{ $failedPercent }}%"></div>
                                                @endif
                                                <div class="w-full flex-1 bg-gradient-to-t from-brand-forest via-brand-sage to-brand-sage"></div>
                                            </div>
                                        @else
                                            <div class="h-[3px] w-full rounded-full bg-brand-cream/15" aria-hidden="true"></div>
                                        @endif
                                    </div>
                                    <span class="text-[9px] font-medium uppercase text-brand-cream/35">{{ substr($day['date']->format('D'), 0, 1) }}</span>
                                </div>
                            @endforeach
                        </div>

                        <p class="mt-2.5 text-xs text-brand-cream/55">
                            @if ($activityTotal === 0)
                                {{ __('Nothing written to a destination in the last 14 days.') }}
                            @else
                                <span class="font-mono font-semibold tabular-nums text-brand-cream">{{ number_format($activityTotal) }}</span>
                                {{ trans_choice('write|writes', $activityTotal) }}@if ($activityFailed > 0)<span class="text-brand-rust">, {{ __(':count failed', ['count' => $activityFailed]) }}</span>@endif.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </x-slot:stats>

        <x-slot:tabs>
            <x-backups-subnav active="storage" />
        </x-slot:tabs>

        {{-- Edit panel --}}
        @if ($editing_id)
            <div wire:key="edit-{{ $editing_id }}" class="border-b border-brand-ink/10 bg-brand-sage/5">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-pencil-square"
                    :title="__('Edit destination')"
                    :note="__('Update the label or credentials, then save.')"
                />
                <div class="space-y-3 px-3 py-3 sm:px-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="bc_edit_name" :value="__('Name')" />
                            <x-text-input id="bc_edit_name" wire:model="editForm.name" type="text" class="mt-1 block w-full" autocomplete="off" />
                            <x-input-error :messages="$errors->get('editForm.name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="bc_edit_provider" :value="__('Storage provider')" />
                            <select id="bc_edit_provider" wire:model.live="editForm.provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                    @php $providerAvailable = \App\Models\BackupConfiguration::isProviderAvailable($p); @endphp
                                    <option value="{{ $p }}" @disabled(! $providerAvailable)>{{ \App\Models\BackupConfiguration::labelForProvider($p) }}@unless ($providerAvailable) — {{ __('coming soon') }}@endunless</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('editForm.provider')" class="mt-2" />
                        </div>
                    </div>

                    @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'editForm', 'form' => $editForm])

                    <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 pt-4">
                        <button type="button" wire:click="cancelEdit" class="px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="updateConfiguration" wire:loading.attr="disabled" wire:target="updateConfiguration">
                            <span wire:loading.remove wire:target="updateConfiguration" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save changes') }}
                            </span>
                            <span wire:loading wire:target="updateConfiguration" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        @endif

        @if ($totalConfigs > 0 || $hasBackupSearch)
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6">
                <div class="w-full sm:max-w-sm">
                    <label for="bc_search" class="sr-only">{{ __('Search') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                            <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <input
                            id="bc_search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search destinations by name…') }}"
                            autocomplete="off"
                            class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                </div>
            </div>
        @endif

        @if (! $hasBackupSearch && $configurations->isEmpty())
            <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-archive-box class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No backup destinations yet') }}</p>
                <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                    {{ __('Add a storage provider to start scheduling backups on your servers.') }}
                </p>
                <button
                    type="button"
                    wire:click="openDestinationModal"
                    class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Add destination') }}
                </button>
            </div>
        @elseif ($hasBackupSearch && $configurations->isEmpty())
            <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                </span>
                <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No destinations match this search.') }}</p>
                <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
            </div>
        @else
            {{-- One row per destination, carrying what actually uses it. The old
                 layout said only "name, provider, added 3 weeks ago", so there
                 was no way to tell a bucket taking nightly dumps from dead weight
                 — or to know what a Delete would break. --}}
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($configurations as $row)
                    @php
                        $providerSlug = $row->provider;
                        $providerLabel = \App\Models\BackupConfiguration::labelForProvider($providerSlug);
                        $badgeClasses = $providerBadge($providerSlug);
                        $isEditing = $editing_id === (string) $row->id;
                        $stats = $usage[$row->id] ?? ['schedules' => 0, 'writes' => 0, 'bytes' => 0, 'lastWriteAt' => null];
                        $unused = $stats['schedules'] === 0 && $stats['writes'] === 0;
                        $trend = $trends[$row->id] ?? [];
                        $trendMax = $trend === [] ? 0 : max($trend);

                        // The confirm copy quotes the real number: "stops 3
                        // schedules" is a decision, "stops schedules" is a shrug.
                        $deleteWarning = $stats['schedules'] > 0
                            ? trans_choice(
                                'Remove this destination? :count schedule points at it and stops shipping until you pick a new one. Backups already written to the bucket are not touched.|Remove this destination? :count schedules point at it and stop shipping until you pick a new one. Backups already written to the bucket are not touched.',
                                $stats['schedules'],
                                ['count' => $stats['schedules']],
                            )
                            : __('Remove this destination? Nothing points at it right now. Backups already written to the bucket are not touched.');
                    @endphp
                    <li wire:key="bc-{{ $row->id }}" @class([
                        'grid gap-x-4 gap-y-3 border-l-[3px] px-3 py-3 transition-colors hover:bg-brand-sand/15 sm:px-4',
                        'lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.1fr)_minmax(0,1fr)_auto_auto] lg:items-center',
                        'border-brand-sage' => ! $unused && ! $isEditing,
                        'border-brand-mist/40' => $unused && ! $isEditing,
                        'border-brand-forest bg-brand-sage/5' => $isEditing,
                    ])>
                        {{-- Identity --}}
                        <div class="flex min-w-0 items-center gap-3">
                            <span @class([
                                'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1',
                                'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => ! $unused,
                                'bg-brand-sand/50 text-brand-mist ring-brand-ink/10' => $unused,
                            ])>
                                <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $row->name }}</span>
                                    @if ($isEditing)
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Editing') }}
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-brand-moss">
                                    <span class="inline-flex shrink-0 items-center rounded border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $badgeClasses }}">{{ $providerLabel }}</span>
                                    <span class="truncate text-brand-mist">{{ __('added :time', ['time' => $row->created_at?->diffForHumans(short: true) ?? '—']) }}</span>
                                </p>
                            </div>
                        </div>

                        {{-- What points at it --}}
                        <div class="min-w-0">
                            @if ($stats['schedules'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/20 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-brand-forest">
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                    {{ trans_choice(':count schedule|:count schedules', $stats['schedules'], ['count' => $stats['schedules']]) }}
                                </span>
                            @elseif ($stats['writes'] > 0)
                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-gold/25 px-2 py-0.5 text-2xs font-bold uppercase tracking-wide text-amber-800">
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-gold" aria-hidden="true"></span>
                                    {{ __('No schedule') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-sand/40 px-2.5 py-1 text-xs font-medium text-brand-mist">
                                    <x-heroicon-m-minus-circle class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Unused') }}
                                </span>
                            @endif
                            @if ($stats['writes'] > 0)
                                <p class="mt-1 truncate text-2xs text-brand-mist">
                                    {{ trans_choice(':count dump landed here|:count dumps landed here', $stats['writes'], ['count' => number_format($stats['writes'])]) }}
                                </p>
                            @endif
                        </div>

                        {{-- Last write --}}
                        <div class="min-w-0 text-xs">
                            @if ($stats['lastWriteAt'])
                                <p class="truncate text-brand-ink">
                                    <span class="font-medium">{{ $stats['lastWriteAt']->diffForHumans(short: true) }}</span>
                                    @if ($stats['bytes'] > 0)
                                        <span class="font-mono tabular-nums text-brand-moss">· {{ \Illuminate\Support\Number::fileSize($stats['bytes']) }}</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 truncate text-brand-mist">{{ __('last write') }}</p>
                            @else
                                <p class="text-brand-mist">{{ __('Never written to') }}</p>
                            @endif
                        </div>

                        {{-- Size trend of what lands here. --}}
                        <div class="hidden lg:block">
                            @if (count($trend) > 1)
                                <div
                                    class="flex h-8 w-24 items-end gap-px"
                                    title="{{ trans_choice('Last :count write size|Last :count write sizes', count($trend), ['count' => count($trend)]) }}"
                                    role="img"
                                    aria-label="{{ __('Recent write sizes') }}"
                                >
                                    @foreach ($trend as $bytes)
                                        <span
                                            class="flex-1 rounded-[1px] {{ $loop->last ? 'bg-brand-forest' : 'bg-brand-sage/70' }}"
                                            style="height: {{ max(12, (int) round($bytes / max(1, $trendMax) * 100)) }}%"
                                        ></span>
                                    @endforeach
                                </div>
                            @else
                                <div class="h-8 w-24" aria-hidden="true"></div>
                            @endif
                        </div>

                        {{-- Actions --}}
                        <div class="flex flex-wrap items-center gap-1.5 lg:justify-end">
                            <button
                                type="button"
                                wire:click="startEdit('{{ $row->id }}')"
                                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Edit') }}
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('deleteConfiguration', ['{{ $row->id }}'], @js(__('Delete backup destination')), @js($deleteWarning), @js(__('Delete')), true)"
                                class="inline-flex h-6 w-6 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
                                title="{{ __('Delete') }}"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Which artifacts actually landed in which bucket. --}}
            <section class="border-t border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-clipboard-document-list"
                    :title="__('Recent writes')"
                    :note="__('Last 25 database dumps that shipped to a destination.')"
                />

                @if ($writes->isEmpty())
                    <div class="px-3 py-8 text-center sm:px-4">
                        <x-heroicon-o-clipboard-document-list class="mx-auto h-7 w-7 text-brand-mist" aria-hidden="true" />
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Nothing has shipped to a destination yet.') }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Pick a destination when you create a database schedule on a server.') }}</p>
                    </div>
                @else
                    @foreach ($writes->groupBy(fn ($write) => $write->created_at->toDateString()) as $day => $dayWrites)
                        @php
                            $dayDate = $dayWrites->first()->created_at;
                            $dayBytes = $dayWrites->sum(fn ($write) => (int) $write->bytes);
                            $dayFailed = $dayWrites->where('status', \App\Models\ServerDatabaseBackup::STATUS_FAILED)->count();
                        @endphp
                        <div wire:key="write-day-{{ $day }}">
                            <div class="flex items-center gap-3 bg-brand-sand/20 px-3 py-1.5 sm:px-4">
                                <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                    @if ($dayDate->isToday())
                                        {{ __('Today') }}
                                    @elseif ($dayDate->isYesterday())
                                        {{ __('Yesterday') }}
                                    @else
                                        {{ $dayDate->format('D, M j') }}
                                    @endif
                                </span>
                                <span class="h-px flex-1 bg-brand-ink/10"></span>
                                @if ($dayFailed > 0)
                                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-rust">{{ __(':count failed', ['count' => $dayFailed]) }}</span>
                                @endif
                                <span class="font-mono text-2xs tabular-nums text-brand-mist">
                                    {{ $dayWrites->count() }} · {{ \Illuminate\Support\Number::fileSize($dayBytes) }}
                                </span>
                            </div>

                            {{-- No row rules inside a day: the tight column of
                                 status nodes carries the rhythm. --}}
                            <ul class="py-1">
                                @foreach ($dayWrites as $write)
                                    @php
                                        $tone = match ($write->status) {
                                            \App\Models\ServerDatabaseBackup::STATUS_COMPLETED => ['bg-brand-sage text-brand-cream', 'heroicon-m-check', __('Done')],
                                            \App\Models\ServerDatabaseBackup::STATUS_FAILED => ['bg-brand-rust text-brand-cream', 'heroicon-m-x-mark', __('Failed')],
                                            default => ['bg-brand-gold text-brand-ink', 'heroicon-m-ellipsis-horizontal', __('Pending')],
                                        };
                                    @endphp
                                    <li wire:key="write-{{ $write->id }}" class="relative flex items-center gap-3 px-3 py-1.5 transition-colors hover:bg-brand-sand/20 sm:px-4">
                                        <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-[3px] ring-brand-cream {{ $tone[0] }}">
                                            <x-dynamic-component :component="$tone[1]" class="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-brand-ink">
                                                <span class="font-semibold">{{ $write->serverDatabase?->name ?? '—' }}</span>
                                                <span class="text-brand-mist">{{ __('to') }}</span>
                                                {{ $write->backupConfiguration?->name ?? __('unknown destination') }}
                                            </p>
                                            <p class="mt-0.5 truncate text-xs text-brand-mist">
                                                <span @class([
                                                    'font-medium',
                                                    'text-brand-rust' => $write->status === \App\Models\ServerDatabaseBackup::STATUS_FAILED,
                                                    'text-brand-moss' => $write->status !== \App\Models\ServerDatabaseBackup::STATUS_FAILED,
                                                ])>{{ $tone[2] }}</span>
                                                · <span class="font-mono tabular-nums">{{ $write->bytes ? \Illuminate\Support\Number::fileSize((int) $write->bytes) : __('no artifact') }}</span>
                                                · {{ $write->serverDatabase?->server?->name ?? '—' }}
                                                @if ($write->error_message)
                                                    · <span class="text-brand-rust">{{ \Illuminate\Support\Str::limit($write->error_message, 80) }}</span>
                                                @endif
                                            </p>
                                        </div>
                                        <time
                                            class="shrink-0 font-mono text-xs tabular-nums text-brand-moss"
                                            datetime="{{ $write->created_at->toIso8601String() }}"
                                            title="{{ $write->created_at->format('Y-m-d H:i:s') }}"
                                        >
                                            {{ $write->created_at->diffForHumans(short: true) }}
                                        </time>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endif
            </section>

            {{-- What does and does not use these destinations. Worth saying once
                 here: the counts above are dumps-only for a reason. --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss sm:px-4">
                <p class="min-w-0 flex-1">
                    <span class="font-semibold text-brand-ink">{{ __('What ships here:') }}</span>
                    {{ __('database dumps and cache snapshots. Site archives land on the server or the control plane, and provider images stay in your own cloud account — neither uses a destination, so neither is counted above.') }}
                </p>
                <a href="{{ route('backups.databases') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                    {{ __('Database schedules') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                </a>
            </div>
        @endif
    </x-profile-shell>

    {{-- The shared add-destination modal: "connect existing" pastes keys for a
         bucket you already have, "provision" creates a brand-new one on the
         provider using a connected cloud token. Same dialog the server
         workspace opens — see ManagesBackupDestinationModal. --}}
    @include('livewire.servers.partials.backups._add-destination-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
    </div>
</div>
