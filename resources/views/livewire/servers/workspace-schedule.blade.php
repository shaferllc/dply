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

    {{-- Enable scheduler for a site (creates a managed cron entry directly) ----- --}}
    @if ($sites->isNotEmpty())
        <section class="{{ $card }}">
            <header class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-brand-ink">{{ __('Enable scheduler for a site') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Creates a cron entry under the site\'s system user that calls the framework scheduler. Edit it on the Cron jobs page if you need to tweak the cadence or command.') }}</p>
            </header>
            @php
                $input = 'block w-full rounded-lg border border-brand-ink/20 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-2 focus:ring-brand-forest/30';
                $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
            @endphp
            <form wire:submit="enableSchedulerForSite" class="grid gap-3 p-5 sm:grid-cols-4">
                <select wire:model="enable_site_id" class="{{ $input }} sm:col-span-2">
                    <option value="">{{ __('Pick a site…') }}</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                    @endforeach
                </select>
                <select wire:model="enable_framework" class="{{ $input }} sm:col-span-1">
                    <option value="laravel">{{ __('Laravel (schedule:run)') }}</option>
                    <option value="rails">{{ __('Rails (whenever)') }}</option>
                </select>
                <input type="text" wire:model="enable_cron_expression" class="{{ $input }} font-mono sm:col-span-1" placeholder="* * * * *" />
                <button type="submit" class="{{ $btnPrimary }} sm:col-span-4 sm:justify-self-start" @disabled(! $opsReady)>
                    {{ __('Enable scheduler') }}
                </button>
            </form>
            <div class="border-t border-brand-ink/10 px-5 py-3 text-xs text-brand-moss">
                <p>{{ __('Prefer a long-running daemon? ') }}<a href="{{ route('servers.daemons', $server) }}?preset=laravel-schedule" wire:navigate class="font-semibold text-brand-ink underline">{{ __('Add a schedule:work supervisor program') }}</a>{{ __(' instead.') }}</p>
            </div>
        </section>
    @endif
</x-server-workspace-layout>
