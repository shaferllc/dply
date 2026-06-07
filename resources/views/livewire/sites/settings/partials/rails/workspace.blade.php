@php
    $cronUrl = route('sites.cron', ['server' => $server, 'site' => $site]);
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
    $sidekiqPresetUrl = $daemonsUrl.'?preset=sidekiq';
    $solidQueuePresetUrl = $daemonsUrl.'?preset=solid-queue';
    $actionCablePresetUrl = $daemonsUrl.'?preset=action-cable';
@endphp

@if (! $site->isRailsFrameworkDetected())
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Framework') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Rails') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This section appears when your site is detected as a Ruby on Rails application from repository inspection.') }}</p>
            </div>
        </div>
    </section>
@else
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Framework') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Rails') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Background workers, scheduled jobs, and real-time channels for this Rails site.') }}</p>
            </div>
        </div>

        <div class="space-y-6 px-6 py-6 sm:px-7">
        {{-- Sidekiq quick-add (the only one with a built-in supervisor preset today) --}}
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Sidekiq') }}</h3>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Redis-backed background worker for Rails. Launches a managed Supervisor program running bundle exec sidekiq.') }}</p>
                </div>
                <x-primary-button size="sm" href="{{ $sidekiqPresetUrl }}" wire:navigate>
                    {{ __('Add Sidekiq worker') }}
                </x-primary-button>
            </div>
        </div>

        {{-- Solid Queue / Action Cable / whenever notes --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Solid Queue') }}</h3>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Database-backed job queue (Rails 8 default). Runs as bin/jobs under Supervisor.') }}</p>
                <a href="{{ $solidQueuePresetUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Add Solid Queue worker') }} →</a>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Action Cable') }}</h3>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Standalone Puma serving cable/config.ru — the production pattern for Rails websockets.') }}</p>
                <a href="{{ $actionCablePresetUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Add Action Cable daemon') }} →</a>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('whenever / scheduled tasks') }}</h3>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('whenever generates a crontab from config/schedule.rb. Add the cron entries on the per-site Cron jobs page.') }}</p>
                <a href="{{ $cronUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Open Cron jobs') }} →</a>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white p-5">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('All workers for this site') }}</h3>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('See and manage every queue / background worker scoped to this site.') }}</p>
                <a href="{{ $daemonsUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Open Workers') }} →</a>
            </div>
        </div>
        </div>
    </section>
@endif
