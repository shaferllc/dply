@php
    $card = 'dply-card overflow-hidden';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $daemonsRoute = ($contextSiteModel ?? null) !== null
        ? route('sites.daemons', ['server' => $server, 'site' => $contextSiteModel])
        : route('servers.daemons', $server);
    $daemonsLabel = ($contextSiteModel ?? null) !== null ? __('Open site Daemons') : __('Open Daemons');
    $description = ($contextSiteModel ?? null) !== null
        ? __('Supervisor programs scoped to this site that run queue / background workers. The full daemon CRUD lives on the Daemons page — adding from here pre-fills the directory and system user from the site context.')
        : __('A focused view of the Supervisor programs on this server that run queue / background workers. The full daemon CRUD lives on the Daemons page — this page lists what\'s here and helps you add common worker presets.');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="queue-workers"
    :title="__('Queue workers')"
    :description="$description"
    :context-site="$contextSiteModel ?? null"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div @if ($server->supervisor_package_status === null) wire:init="refreshSupervisorInstallStatus" @endif>
    @if ($supervisor_installed === null)
        <p class="mb-4 flex items-center gap-2 text-sm text-brand-moss">
            <x-spinner variant="forest" />
            {{ __('Checking Supervisor installation…') }}
        </p>
    @elseif ($supervisor_installed === false)
        <div class="mb-6 rounded-2xl border border-amber-300/80 bg-amber-50/90 px-5 py-4 sm:flex sm:flex-row sm:items-center sm:justify-between sm:gap-6">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-amber-950">{{ __('Supervisor is not installed') }}</h2>
                <p class="mt-1 text-sm text-amber-900/90">{{ __('Queue workers are Supervisor programs — install Supervisor on the Daemons page before adding workers here.') }}</p>
            </div>
            <a
                href="{{ $daemonsRoute }}"
                wire:navigate
                class="mt-4 inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-950 sm:mt-0"
            >
                {{ __('Go to Daemons to install') }}
            </a>
        </div>
    @endif

    <x-explainer class="mb-4">
        @if (($contextSiteModel ?? null) !== null)
            <p>{{ __('Filtered to this site — only Supervisor programs whose site_id matches are shown here. Use a preset below to add a site-scoped worker; it lands on the site Daemons page with the directory and system user pre-filled.') }}</p>
        @else
            <p>{{ __('Queue workers are Supervisor programs whose program_type matches a known queue framework (Laravel queue/Horizon/Octane/Reverb, Sidekiq, Solid Queue, Celery, BullMQ, generic Node). Programs added here also appear on the Daemons page since they share the same model.') }}</p>
        @endif
        <p class="mt-2 text-xs"><a href="{{ route('servers.activity', $server) }}?category=background" wire:navigate class="font-semibold text-brand-ink underline">{{ __('View background activity →') }}</a></p>
    </x-explainer>

    {{-- At-a-glance counts. --}}
    <section class="grid gap-3 sm:grid-cols-3">
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Active workers') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-forest">{{ $stats['active'] }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inactive') }}</p>
            <p class="mt-1 text-2xl font-semibold {{ $stats['inactive'] > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $stats['inactive'] }}</p>
        </div>
        <div class="dply-card p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total processes') }}</p>
            <p class="mt-1 text-2xl font-semibold text-brand-ink">{{ $stats['total_processes'] }}</p>
        </div>
    </section>

    {{-- Existing queue workers ----------------------------------------------------- --}}
    <section class="{{ $card }}">
        <header class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Active workers') }}</h2>
            <a href="{{ $daemonsRoute }}" wire:navigate class="{{ $btnSecondary }}">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                {{ $daemonsLabel }}
            </a>
        </header>

        @if ($programs->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-brand-moss">
                <p>
                    @if (($contextSiteModel ?? null) !== null)
                        {{ __('No queue workers for this site yet. Use a preset below to add one.') }}
                    @else
                        {{ __('No queue workers configured yet. Use a preset below to add one.') }}
                    @endif
                </p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($programs as $program)
                    <li class="flex items-center gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $program->slug }}</p>
                            <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $program->command }}</p>
                            <p class="mt-1 flex items-center gap-3 text-[11px] uppercase tracking-wide text-brand-mist">
                                <span>{{ $program->program_type }}</span>
                                <span>·</span>
                                <span>{{ trans_choice('{1}:count process|[2,*]:count processes', (int) $program->numprocs, ['count' => (int) $program->numprocs]) }}</span>
                                @if ($program->site_id && ($contextSiteModel ?? null) === null)
                                    <span>·</span>
                                    <span>{{ __('site-scoped') }}</span>
                                @endif
                                @if (! $program->is_active)
                                    <span>·</span>
                                    <span class="text-amber-700">{{ __('inactive') }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($program->is_active)
                                <button
                                    type="button"
                                    wire:click="restartWorker('{{ $program->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="restartWorker"
                                    class="{{ $btnSecondary }}"
                                    @disabled(! $opsReady || $supervisor_installed !== true)
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    {{ __('Restart') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="stopWorker('{{ $program->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="stopWorker"
                                    wire:confirm="{{ __('Stop :slug?', ['slug' => $program->slug]) }}"
                                    class="{{ $btnSecondary }}"
                                    @disabled(! $opsReady || $supervisor_installed !== true)
                                >
                                    <x-heroicon-o-stop class="h-4 w-4" />
                                    {{ __('Stop') }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="startWorker('{{ $program->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="startWorker"
                                    class="{{ $btnSecondary }}"
                                    @disabled(! $opsReady || $supervisor_installed !== true)
                                >
                                    <x-heroicon-o-play class="h-4 w-4" />
                                    {{ __('Start') }}
                                </button>
                            @endif
                            <a
                                href="{{ $daemonsRoute }}#program-{{ $program->id }}"
                                wire:navigate
                                class="{{ $btnSecondary }}"
                            >
                                {{ __('Manage') }}
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Preset shortcuts ----------------------------------------------------------- --}}
    <section class="{{ $card }}">
        <header class="border-b border-brand-ink/10 px-5 py-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Add a worker') }}</h2>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Picking a preset opens the Daemons page with the worker form prefilled — adjust the directory and add the program from there.') }}</p>
        </header>
        <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($presets as $preset)
                <a
                    href="{{ $daemonsRoute }}?preset={{ $preset['key'] }}"
                    wire:navigate
                    class="group flex flex-col gap-2 rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm transition-colors hover:border-brand-ink/30 hover:bg-brand-sand/30"
                >
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ $preset['label'] }}</h3>
                        <x-heroicon-o-arrow-right class="h-4 w-4 text-brand-mist group-hover:text-brand-ink" />
                    </div>
                    <p class="text-xs leading-relaxed text-brand-moss">{{ $preset['description'] }}</p>
                    <p class="mt-auto text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $preset['framework'] }}</p>
                </a>
            @endforeach
        </div>
    </section>
    </div>{{-- /supervisor-install scope --}}
</x-server-workspace-layout>
