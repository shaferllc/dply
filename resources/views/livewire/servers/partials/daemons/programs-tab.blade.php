@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    $programsTotal = $server->supervisorPrograms->count();
    $programsFiltered = $filteredSupervisorPrograms->count();
@endphp

{{-- systemd-managed workers live elsewhere (SiteProcess → dply-site-*.service)
     and never appear in the Supervisor list — surface them so "where's my
     Horizon worker?" answers itself and nobody double-creates one here. --}}
@if (isset($systemdWorkers) && $systemdWorkers->isNotEmpty())
    <section class="dply-card mb-5 overflow-hidden border border-sky-200">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-sky-200 bg-sky-50 px-6 py-4 sm:px-7">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-sky-900">{{ __(':n worker(s) run via systemd — not Supervisor', ['n' => $systemdWorkers->count()]) }}</h3>
                <p class="mt-1 max-w-3xl text-sm text-sky-800">{{ __('These run as systemd units (dply’s native worker mechanism), so they don’t show in the Supervisor program list below. Manage them where they were created — adding a Supervisor program here would create a SECOND, parallel worker.') }}</p>
            </div>
            @if (! empty($serverIsWorkerHost))
                <a href="{{ route('servers.worker-pool', $server) }}" wire:navigate class="shrink-0 rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-sky-700">{{ __('Open Worker Pool') }}</a>
            @endif
        </div>
        <div class="divide-y divide-sky-100">
            @foreach ($systemdWorkers as $w)
                <div class="flex flex-wrap items-center gap-2 px-6 py-2.5 text-sm sm:px-7">
                    <span class="font-semibold text-brand-ink">{{ $w['name'] }}</span>
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[11px] uppercase tracking-wide text-brand-moss">{{ $w['type'] }}</span>
                    <span class="text-xs text-brand-moss">{{ $w['site_name'] }}</span>
                    <code class="ml-auto truncate font-mono text-[11px] text-brand-moss" title="{{ $w['command'] }}">{{ $w['command'] }}</code>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- Programs list card. Header carries the primary actions (Sync, Restart all,
     Add program) and a count chip; rows below. --}}
<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $contextSiteModel ? __('Programs for this site') : __('Programs on this server') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Add a new Supervisor program or edit / start / stop / restart / delete an existing one. Sync afterwards to apply changes on the server.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($programsTotal > 0)
                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $programsTotal }}</span>
                @endif
                <button
                    type="button"
                    wire:click="loadProgramStatuses"
                    wire:loading.attr="disabled"
                    wire:target="loadProgramStatuses"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Refresh') }}
                </button>
                <button
                    type="button"
                    wire:click="runPreflightPathCheck"
                    wire:loading.attr="disabled"
                    wire:target="runPreflightPathCheck"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-folder class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Path check') }}
                </button>
                <button
                    type="button"
                    wire:click="restartAllPrograms({{ $restartAllConfirmMessage !== '' ? 'true' : 'false' }})"
                    @if ($restartAllConfirmMessage !== '')
                        wire:confirm="{{ $restartAllConfirmMessage }}"
                    @endif
                    wire:loading.attr="disabled"
                    wire:target="restartAllPrograms"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="restartAllPrograms" class="inline-flex items-center gap-1.5">
                        <x-heroicon-m-arrow-path-rounded-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Restart all') }}
                    </span>
                    <span wire:loading wire:target="restartAllPrograms" class="inline-flex items-center gap-1.5 whitespace-nowrap">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Working…') }}
                    </span>
                </button>
                <button
                    type="button"
                    wire:click="syncSupervisor"
                    wire:loading.attr="disabled"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <x-heroicon-m-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Sync') }}
                </button>
                <button
                    type="button"
                    wire:click="openCreateDaemonModal"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Add program') }}
                </button>
            </div>
        </div>
    </div>

    @if ($contextSiteModel)
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-7">
            <span class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Show') }}</span>
            <div class="inline-flex items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm" role="group" aria-label="{{ __('Program list scope') }}">
                <button type="button" wire:click="$set('programs_list_scope', 'site')" @class([
                    'rounded-lg px-3 py-1 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => $programs_list_scope === 'site',
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $programs_list_scope !== 'site',
                ])>{{ __('This site only') }}</button>
                <button type="button" wire:click="$set('programs_list_scope', 'all')" @class([
                    'rounded-lg px-3 py-1 text-xs font-semibold transition',
                    'bg-brand-ink text-brand-cream shadow-sm' => $programs_list_scope === 'all',
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $programs_list_scope !== 'all',
                ])>{{ __('All programs on server') }}</button>
            </div>
        </div>
    @endif

    @if ($server->supervisorPrograms->isEmpty())
        <div class="px-6 py-12 text-center sm:px-7">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-cpu-chip class="h-6 w-6" aria-hidden="true" />
            </span>
            <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No programs yet') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Add one, then sync to write configs and reload Supervisor.') }}
            </p>
            <button
                type="button"
                wire:click="openCreateDaemonModal"
                @disabled($supervisor_installed !== true)
                class="mt-5 inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Add program') }}
            </button>
        </div>
    @elseif ($filteredSupervisorPrograms->isEmpty())
        <div class="px-6 py-12 text-center sm:px-7">
            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No programs match this filter.') }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Choose “All programs on server” or add a program linked to this site.') }}</p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($filteredSupervisorPrograms as $sp)
                @php
                    // Have we fetched supervisorctl statuses this page-load? When the map is
                    // empty we simply don't know the state yet (Refresh not run) — distinct from
                    // a program that WAS probed but is absent from the output.
                    $statusesLoaded = ! empty($program_status_map);
                    $pst = $program_status_map[$sp->id]['state'] ?? 'unknown';
                    $badgeClass = $programStatusBadgeClass($pst);

                    // A program that exists in Dply but is missing from the last supervisorctl
                    // output ("NOT REPORTED"): its conf was never applied / was removed on the box.
                    // Start/Stop would fail with "no such process" — offer Sync instead.
                    $isUnreported = $statusesLoaded && $pst === 'unknown';
                    // Reported + running-ish → only Stop makes sense.
                    $isRunningState = in_array($pst, ['running', 'starting'], true);
                    // Reported + not running → only Start makes sense.
                    $isStoppedState = in_array($pst, ['stopped', 'exited', 'fatal', 'backoff'], true);
                @endphp
                <li id="program-{{ $sp->id }}" class="relative flex flex-col scroll-mt-24 sm:flex-row" wire:key="program-{{ $sp->id }}">
                    <span
                        @class([
                            'absolute bottom-0 left-0 top-0 w-1',
                            'bg-brand-forest' => $sp->is_active,
                            'bg-brand-mist' => ! $sp->is_active,
                        ])
                        aria-hidden="true"
                    ></span>
                    <div class="min-w-0 flex-1 py-4 pl-5 pr-4 sm:py-5 sm:pl-6 sm:pr-6">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $sp->slug }}</p>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $badgeClass }}">{{ $pst }}</span>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">
                            <span class="font-medium text-brand-ink/80">{{ $sp->program_type }}</span>
                            · {{ __('user') }} {{ $sp->user }}
                            · {{ __('numprocs') }} {{ $sp->numprocs }}
                            @if ($sp->site_id && $sitesForServer->firstWhere('id', $sp->site_id))
                                · {{ __('site') }} {{ $sitesForServer->firstWhere('id', $sp->site_id)->name }}
                            @endif
                        </p>
                        <p class="mt-2 break-all font-mono text-xs leading-relaxed text-brand-moss">{{ $sp->command }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ $sp->effectiveDirectory() }}</p>
                        @if ($isUnreported)
                            <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                                <p class="font-semibold">{{ __('Not reported by Supervisor') }}</p>
                                <p class="mt-0.5 text-amber-800">{{ __('This program is saved in Dply but is missing from the last supervisorctl output, so Start / Stop won’t work yet (Supervisor returns “no such process”). Sync re-applies its config to the server and reloads Supervisor so it’s registered.') }}</p>
                            </div>
                        @endif
                        @if ($orgServersForCopy->isNotEmpty() && ! $contextSiteModel)
                            <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3 text-xs">
                                <p class="font-medium text-brand-ink">{{ __('Copy to another server') }}</p>
                                <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                    @if ($copy_source_program_id === $sp->id)
                                        <select wire:model="copy_target_server_id" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                                            <option value="">{{ __('Target server…') }}</option>
                                            @foreach ($orgServersForCopy as $os)
                                                <option value="{{ $os->id }}">{{ $os->name }}</option>
                                            @endforeach
                                        </select>
                                        <input
                                            type="text"
                                            wire:model="copy_new_slug"
                                            class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs"
                                            placeholder="{{ __('new-slug') }}"
                                        />
                                        <button
                                            type="button"
                                            wire:click="copyProgramToServer"
                                            class="rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white"
                                        >{{ __('Copy') }}</button>
                                        <button type="button" wire:click="$set('copy_source_program_id', '')" class="text-brand-moss hover:underline">{{ __('Cancel') }}</button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="$set('copy_source_program_id', '{{ $sp->id }}')"
                                            class="text-brand-forest hover:underline"
                                        >{{ __('Prepare copy…') }}</button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-1 border-t border-brand-ink/5 bg-brand-sand/10 px-2 py-3 sm:border-l sm:border-t-0 sm:px-3">
                        <button
                            type="button"
                            wire:click="openProgramLogs('{{ $sp->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="openProgramLogs"
                            @disabled($supervisor_installed !== true)
                            class="rounded-lg p-2 text-brand-ink hover:bg-white disabled:opacity-40"
                            title="{{ __('Logs') }}"
                        >
                            <x-heroicon-o-document-text class="h-5 w-5" />
                        </button>
                        <button
                            type="button"
                            wire:click="beginEditProgram('{{ $sp->id }}')"
                            class="rounded-lg p-2 text-brand-ink hover:bg-white"
                            title="{{ __('Edit') }}"
                        >
                            <x-heroicon-o-pencil-square class="h-5 w-5" />
                        </button>
                        @if ($isUnreported)
                            <button
                                type="button"
                                wire:click="syncOneProgram('{{ $sp->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="syncOneProgram"
                                @disabled($supervisor_installed !== true)
                                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-40"
                                title="{{ __('Re-register this program with Supervisor on the server') }}"
                            >
                                <x-heroicon-o-cloud-arrow-up class="h-4 w-4" />
                                {{ __('Sync') }}
                            </button>
                        @else
                            {{-- Start only when the program is reported as stopped/exited/fatal/backoff.
                                 Stop only when reported running/starting. When the state is unknown
                                 (statuses not refreshed yet), show neither — the operator should
                                 Refresh first. --}}
                            @if ($isStoppedState)
                                <button
                                    type="button"
                                    wire:click="startOneProgram('{{ $sp->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="startOneProgram"
                                    @disabled($supervisor_installed !== true)
                                    class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50 disabled:opacity-40"
                                    title="{{ __('Start') }}"
                                >
                                    <x-heroicon-o-play class="h-5 w-5" />
                                </button>
                            @endif
                            @if ($isRunningState)
                                <button
                                    type="button"
                                    wire:click="stopOneProgram('{{ $sp->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="stopOneProgram"
                                    @disabled($supervisor_installed !== true)
                                    class="rounded-lg p-2 text-amber-800 hover:bg-amber-50 disabled:opacity-40"
                                    title="{{ __('Stop') }}"
                                >
                                    <x-heroicon-o-stop class="h-5 w-5" />
                                </button>
                            @endif
                        @endif
                        @unless ($isUnreported)
                            <button
                                type="button"
                                wire:click="restartOneProgram('{{ $sp->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="restartOneProgram"
                                @disabled($supervisor_installed !== true)
                                class="rounded-lg p-2 text-brand-forest hover:bg-emerald-50 disabled:opacity-40"
                                title="{{ __('Restart') }}"
                            >
                                <x-heroicon-o-arrow-path class="h-5 w-5" />
                            </button>
                        @endunless
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('deleteSupervisorProgram', ['{{ $sp->id }}'], @js(__('Delete program')), @js(__('Delete this program? Sync Supervisor afterward to remove its config from the server.')), @js(__('Delete program')), true)"
                            class="rounded-lg p-2 text-red-600 hover:bg-red-50"
                            title="{{ __('Delete') }}"
                        >
                            <x-heroicon-o-trash class="h-5 w-5" />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-xs text-brand-moss sm:px-7">
            {{ __('Removing a program deletes it from Dply and its conf file on the next sync. Orphan conf files are cleaned when you sync.') }}
        </p>
    @endif

    @if ($preflight_messages !== [])
        <div class="border-t border-brand-ink/10 bg-white px-6 py-4 sm:px-7">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Path check') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-ink">
                @foreach ($preflight_messages as $msg)
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</section>

@php
    // Only show the import card when there is at least one OTHER site to import from.
    // Site context: destination is fixed, so any other site qualifies ($sitesForImport excludes it).
    // Server context: a source/destination pair is only possible with 2+ sites on the server.
    $showImportFromSite = $contextSiteModel !== null
        ? $sitesForImport->isNotEmpty()
        : $sitesForServer->count() >= 2;
@endphp

@if ($showImportFromSite)
    @include('livewire.servers.partials.daemons._import-from-site')
@endif

@include('livewire.servers.partials.supervisor-program-modal', [
    'modalName' => 'daemon-program-modal',
    'titleNew' => __('New Supervisor program'),
    'titleEdit' => __('Edit Supervisor program'),
    'submitNew' => __('Add program'),
    'submitEdit' => __('Update program'),
])

@include('livewire.servers.partials.daemons.daemon-program-logs-modal')

