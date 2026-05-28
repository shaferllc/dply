@include('livewire.servers.partials.workspace-flashes')
@include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

<div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif class="space-y-6">
@if ($supervisor_installed === null)
    <p class="flex items-center gap-2 text-sm text-brand-moss">
        <x-spinner variant="forest" />
        {{ __('Checking Supervisor installation…') }}
    </p>
@elseif ($supervisor_installed === false)
    <section class="dply-card overflow-hidden border-amber-200">
        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Supervisor is not installed') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Queue workers are Supervisor programs — install Supervisor on the Daemons page before adding workers here.') }}
                        </p>
                    </div>
                </div>
                <a
                    href="{{ $daemonsRoute }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest sm:self-auto"
                >
                    <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Install via Daemons') }}
                </a>
            </div>
        </div>
    </section>
@endif

<x-explainer>
    @if (($contextSiteModel ?? null) !== null)
        <p>{{ __('Filtered to this site — only Supervisor programs whose site_id matches are shown here. Use a preset below to add a site-scoped worker; it lands on the site Daemons page with the directory and system user pre-filled.') }}</p>
    @else
        <p>{{ __('Queue workers are Supervisor programs whose program_type matches a known queue framework (Laravel queue/Horizon/Octane/Reverb, Sidekiq, Solid Queue, Celery, BullMQ, generic Node). Programs added here also appear on the Daemons page since they share the same model.') }}</p>
    @endif
    <p class="mt-2 text-xs"><a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="font-semibold text-brand-ink underline">{{ __('View background activity →') }}</a></p>
</x-explainer>

{{-- At-a-glance counts. --}}
<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['violet'] }}">
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Workers') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workers at a glance') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Counts across queue-class Supervisor programs visible here.') }}</p>
            </div>
        </div>
    </div>
    <dl class="grid grid-cols-1 gap-2 p-6 sm:grid-cols-3 sm:p-7">
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-emerald-200 bg-emerald-50/60' => $stats['active'] > 0,
            'border-brand-ink/10 bg-white' => $stats['active'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Active') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stats['active'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('running|running', $stats['active']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Currently supervised') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-amber-200 bg-amber-50/60' => $stats['inactive'] > 0,
            'border-brand-ink/10 bg-white' => $stats['inactive'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inactive') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stats['inactive'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('stopped|stopped', $stats['inactive']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Not currently running') }}</p>
        </div>
        <div @class([
            'rounded-2xl border px-4 py-3 shadow-sm',
            'border-brand-sage/30 bg-brand-sage/8' => $stats['total_processes'] > 0,
            'border-brand-ink/10 bg-white' => $stats['total_processes'] === 0,
        ])>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Processes') }}</dt>
            <dd class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $stats['total_processes'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ trans_choice('worker|workers', $stats['total_processes']) }}</span>
            </dd>
            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Sum of numprocs') }}</p>
        </div>
    </dl>
</section>

<x-server-workspace-tablist :aria-label="__('Queue workers sections')">
    <x-server-workspace-tab id="queue-tab-workers" :active="$queue_workspace_tab === 'workers'" wire:click="setQueueWorkspaceTab('workers')">
        <span class="inline-flex items-center gap-1.5">
            <x-heroicon-o-queue-list class="h-4 w-4" aria-hidden="true" />
            {{ __('Workers') }}
        </span>
    </x-server-workspace-tab>
    <x-server-workspace-tab id="queue-tab-add" :active="$queue_workspace_tab === 'add'" wire:click="setQueueWorkspaceTab('add')">
        <span class="inline-flex items-center gap-1.5">
            <x-heroicon-o-plus-circle class="h-4 w-4" aria-hidden="true" />
            {{ __('Add worker') }}
        </span>
    </x-server-workspace-tab>
</x-server-workspace-tablist>

<div class="relative" wire:loading.class="opacity-60 pointer-events-none transition-opacity duration-150" wire:target="setQueueWorkspaceTab">

@if ($queue_workspace_tab === 'workers')
<x-server-workspace-tab-panel id="queue-panel-workers" labelled-by="queue-tab-workers" panel-class="space-y-6">
{{-- Existing queue workers ----------------------------------------------------- --}}
<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sand'] }}">
                <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Library') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Active workers') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Start, stop, restart, or open the full daemon page for any queue worker.') }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if ($programs->isNotEmpty())
                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $programs->count() }}</span>
                @endif
                <a
                    href="{{ $daemonsRoute }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                >
                    <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ $daemonsLabel }}
                </a>
            </div>
        </div>
    </div>

    @if ($programs->isEmpty())
        <div class="px-6 py-12 text-center sm:px-7">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-queue-list class="h-6 w-6" aria-hidden="true" />
            </span>
            <p class="mt-4 text-sm font-semibold text-brand-ink">
                @if (($contextSiteModel ?? null) !== null)
                    {{ __('No queue workers for this site yet') }}
                @else
                    {{ __('No queue workers configured yet') }}
                @endif
            </p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Pick a preset below to scaffold one.') }}
            </p>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($programs as $program)
                <li wire:key="qw-{{ $program->id }}" class="flex items-center gap-4 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <p class="truncate font-mono text-sm font-semibold text-brand-ink">{{ $program->slug }}</p>
                            @if ($program->is_active)
                                <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
                                    <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Running') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                    <x-heroicon-m-stop class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Stopped') }}
                                </span>
                            @endif
                            @if ($program->site_id && ($contextSiteModel ?? null) === null)
                                <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <x-heroicon-m-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    {{ __('Site-scoped') }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-1 break-all font-mono text-[11px] text-brand-moss">{{ $program->command }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="text-[10px] uppercase tracking-wide">{{ __('Type') }}</span>
                                <span class="font-mono text-brand-ink">{{ $program->program_type }}</span>
                            </span>
                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                            <span class="inline-flex items-center gap-1">
                                <span class="font-mono tabular-nums text-brand-ink">{{ (int) $program->numprocs }}</span>
                                {{ trans_choice('process|processes', (int) $program->numprocs) }}
                            </span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        @if ($program->is_active)
                            <button
                                type="button"
                                wire:click="restartWorker('{{ $program->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="restartWorker"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                @disabled(! $opsReady || $supervisor_installed !== true)
                            >
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Restart') }}
                            </button>
                            <button
                                type="button"
                                wire:click="stopWorker('{{ $program->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="stopWorker"
                                wire:confirm="{{ __('Stop :slug?', ['slug' => $program->slug]) }}"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                @disabled(! $opsReady || $supervisor_installed !== true)
                            >
                                <x-heroicon-m-stop class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Stop') }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="startWorker('{{ $program->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="startWorker"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                @disabled(! $opsReady || $supervisor_installed !== true)
                            >
                                <x-heroicon-m-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Start') }}
                            </button>
                        @endif
                        <a
                            href="{{ $daemonsRoute }}#program-{{ $program->id }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-m-cog-6-tooth class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Manage') }}
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

</x-server-workspace-tab-panel>
@endif

@if ($queue_workspace_tab === 'add')
<x-server-workspace-tab-panel id="queue-panel-add" labelled-by="queue-tab-add" panel-class="space-y-6">
{{-- Preset shortcuts ----------------------------------------------------------- --}}
<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Presets') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add a worker') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Picking a preset opens the Daemons page with the worker form prefilled — adjust the directory and add the program from there.') }}</p>
            </div>
        </div>
    </div>
    <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-3">
        @foreach ($presets as $preset)
            <a
                href="{{ $daemonsRoute }}?preset={{ $preset['key'] }}"
                wire:navigate
                class="group flex flex-col rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-sage/30 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['sage'] }}">
                        <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-hover:text-brand-sage" aria-hidden="true" />
                </div>
                <p class="mt-3 text-sm font-semibold text-brand-ink">{{ $preset['label'] }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $preset['description'] }}</p>
                <p class="mt-3 inline-flex w-fit items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $preset['framework'] }}</p>
            </a>
        @endforeach
    </div>
</section>
</x-server-workspace-tab-panel>
@endif

</div>{{-- /tab container --}}
</div>{{-- /supervisor-install scope --}}
