@php
    $cronUrl = route('sites.cron', ['server' => $server, 'site' => $site]);
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
    $queueWorkersUrl = route('sites.queue-workers', ['server' => $server, 'site' => $site]);
    $sidekiqPresetUrl = $daemonsUrl.'?preset=sidekiq';
    $solidQueuePresetUrl = $daemonsUrl.'?preset=solid-queue';
    $actionCablePresetUrl = $daemonsUrl.'?preset=action-cable';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

@if (! $site->isRailsFrameworkDetected())
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </span>
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
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </span>
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
                <a href="{{ $sidekiqPresetUrl }}" wire:navigate class="{{ $btnPrimary }}">
                    {{ __('Add Sidekiq worker') }}
                </a>
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
                <a href="{{ $queueWorkersUrl }}" wire:navigate class="mt-3 inline-flex text-xs font-semibold text-brand-ink underline">{{ __('Open Queue workers') }} →</a>
            </div>
        </div>
        </div>
    </section>
@endif
