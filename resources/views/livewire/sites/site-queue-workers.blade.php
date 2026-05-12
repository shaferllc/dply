@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $card = 'dply-card overflow-hidden';
    $siteDaemonsRoute = route('sites.daemons', ['server' => $server, 'site' => $site]);
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Queue workers') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Queue workers')"
                :description="__('Supervisor programs scoped to this site that run queue / background workers. The full daemon CRUD lives on the Daemons page — adding from here pre-fills the directory and system user from the site context.')"
                flush
                compact
            />

            {{-- At-a-glance counts (matches the server-level page). --}}
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

            <section class="{{ $card }}">
                <header class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Active workers') }}</h2>
                    <a href="{{ $siteDaemonsRoute }}" wire:navigate class="{{ $btnSecondary }}">
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                        {{ __('Open site Daemons') }}
                    </a>
                </header>

                @if ($programs->isEmpty())
                    <div class="px-5 py-10 text-center text-sm text-brand-moss">
                        <p>{{ __('No queue workers for this site yet. Use a preset below to add one.') }}</p>
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
                                        @if (! $program->is_active)
                                            <span>·</span>
                                            <span class="text-amber-700">{{ __('inactive') }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($program->is_active)
                                        <button type="button" wire:click="restartWorker('{{ $program->id }}')" wire:loading.attr="disabled" wire:target="restartWorker" class="{{ $btnSecondary }}">
                                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                                            {{ __('Restart') }}
                                        </button>
                                        <button type="button" wire:click="stopWorker('{{ $program->id }}')" wire:loading.attr="disabled" wire:target="stopWorker" wire:confirm="{{ __('Stop :slug?', ['slug' => $program->slug]) }}" class="{{ $btnSecondary }}">
                                            <x-heroicon-o-stop class="h-4 w-4" />
                                            {{ __('Stop') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="startWorker('{{ $program->id }}')" wire:loading.attr="disabled" wire:target="startWorker" class="{{ $btnSecondary }}">
                                            <x-heroicon-o-play class="h-4 w-4" />
                                            {{ __('Start') }}
                                        </button>
                                    @endif
                                    <a href="{{ $siteDaemonsRoute }}#program-{{ $program->id }}" wire:navigate class="{{ $btnSecondary }}">
                                        {{ __('Manage') }}
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="{{ $card }}">
                <header class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Add a worker') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Picking a preset opens the site Daemons page with the form prefilled.') }}</p>
                </header>
                <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($presets as $preset)
                        <a href="{{ $siteDaemonsRoute }}?preset={{ $preset['key'] }}" wire:navigate class="group flex flex-col gap-2 rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm transition-colors hover:border-brand-ink/30 hover:bg-brand-sand/30">
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
        </main>
    </div>
</div>
