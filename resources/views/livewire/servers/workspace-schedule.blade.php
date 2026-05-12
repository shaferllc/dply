@php
    $card = 'dply-card overflow-hidden';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $cronRoute = route('servers.cron', $server);
    $daemonsRoute = route('servers.daemons', $server);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="schedule"
    :title="__('Schedule')"
    :description="__('Framework schedulers running on this server — Laravel schedule:run, Rails whenever, Celery beat, etc. Schedulers are usually a single cron entry per site (or a long-running supervisor process). Edit the underlying entries on the Cron jobs or Daemons pages.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer class="mb-4">
        <p>{{ __('Anything that looks like a framework scheduler is listed below — both cron entries (the usual setup) and supervisor daemons (the schedule:work pattern). Edit them on their owning page; this is just a focused index.') }}</p>
    </x-explainer>

    {{-- Cron-driven schedulers ----------------------------------------------------- --}}
    <section class="{{ $card }}">
        <header class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-4">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Cron-driven schedulers') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Cron entries containing schedule:run / whenever / celery beat / etc.') }}</p>
            </div>
            <a href="{{ $cronRoute }}" wire:navigate class="{{ $btnSecondary }}">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                {{ __('Open Cron jobs') }}
            </a>
        </header>

        @if ($cronEntries->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-brand-moss">
                <p>{{ __('No scheduler-style cron entries detected.') }}</p>
                <a href="{{ $cronRoute }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Add one on the Cron jobs page') }}</a>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($cronEntries as $entry)
                    <li class="flex items-start gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-xs text-brand-ink">{{ $entry->cron_expression }}  {{ $entry->command }}</p>
                            <p class="mt-1 flex items-center gap-3 text-[11px] uppercase tracking-wide text-brand-mist">
                                @if ($entry->user)
                                    <span>{{ $entry->user }}</span>
                                @endif
                                @if ($entry->site_id)
                                    <span>·</span>
                                    <span>{{ optional($entry->site)->name ?? __('site-scoped') }}</span>
                                @endif
                                @if (! $entry->enabled)
                                    <span>·</span>
                                    <span class="text-amber-700">{{ __('disabled') }}</span>
                                @endif
                                @if ($entry->last_run_at)
                                    <span>·</span>
                                    <span>{{ __('last :ts', ['ts' => $entry->last_run_at->diffForHumans()]) }}</span>
                                @endif
                            </p>
                        </div>
                        <a href="{{ $cronRoute }}#cron-{{ $entry->id }}" wire:navigate class="{{ $btnSecondary }}">
                            {{ __('Edit') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Daemon-driven schedulers --------------------------------------------------- --}}
    <section class="{{ $card }}">
        <header class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-4">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Daemon-driven schedulers') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Supervisor programs running schedule:work or similar.') }}</p>
            </div>
            <a href="{{ $daemonsRoute }}" wire:navigate class="{{ $btnSecondary }}">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                {{ __('Open Daemons') }}
            </a>
        </header>

        @if ($schedulerDaemons->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-brand-moss">
                <p>{{ __('No scheduler daemons configured.') }}</p>
                <a href="{{ $daemonsRoute }}?preset=laravel-schedule" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Add a Laravel schedule:work daemon') }}</a>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($schedulerDaemons as $daemon)
                    <li class="flex items-start gap-4 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $daemon->slug }}</p>
                            <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $daemon->command }}</p>
                        </div>
                        <a href="{{ $daemonsRoute }}#program-{{ $daemon->id }}" wire:navigate class="{{ $btnSecondary }}">
                            {{ __('Manage') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Add scheduler for a site --------------------------------------------------- --}}
    @if ($sites->isNotEmpty())
        <section class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Enable scheduler for a site') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Quick links to the per-site Cron / Daemons pages with the scheduler preset.') }}</p>
            </header>
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($sites as $site)
                    <li class="flex items-center gap-4 px-5 py-3">
                        <p class="min-w-0 flex-1 truncate text-sm font-medium text-brand-ink">{{ $site->name }}</p>
                        <a href="{{ route('sites.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="{{ $btnSecondary }}">
                            {{ __('Add scheduler cron') }}
                        </a>
                        <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}?preset=laravel-schedule" wire:navigate class="{{ $btnSecondary }}">
                            {{ __('Add schedule:work daemon') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-server-workspace-layout>
