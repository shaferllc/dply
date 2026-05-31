@if ($sites->isEmpty())
    <section class="dply-card overflow-hidden">
        <div class="px-6 py-12 text-center sm:px-7">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-calendar-days class="h-6 w-6" aria-hidden="true" />
            </span>
            <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No sites on this server yet.') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">{{ __('Add a site first, then return here to enable its scheduler.') }}</p>
        </div>
    </section>
@else
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Enable') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Enable scheduler for a site') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Creates a cron entry under the site\'s system user and wraps it with dply-scheduler-tick for heartbeat tracking.') }}</p>
            </div>
        </div>

        @if (! empty($preflight_results))
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-7">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Preflight results') }}</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($preflight_results as $check)
                        @php
                            $statusChip = match ($check['status']) {
                                'pass' => ['classes' => 'bg-emerald-50 text-emerald-800 ring-emerald-200', 'label' => __('pass')],
                                'warn' => ['classes' => 'bg-amber-50 text-amber-900 ring-amber-200', 'label' => __('warn')],
                                default => ['classes' => 'bg-red-50 text-red-800 ring-red-200', 'label' => __('fail')],
                            };
                        @endphp
                        <li class="flex flex-wrap items-baseline gap-2 text-xs">
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide ring-1 {{ $statusChip['classes'] }}">{{ $statusChip['label'] }}</span>
                            <span class="font-mono text-brand-mist">{{ $check['key'] }}</span>
                            <span class="text-brand-moss">{{ $check['message'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form wire:submit="enableSchedulerForSite" class="space-y-4 p-6 sm:p-7">
            @if ($contextSite)
                <div>
                    <x-input-label for="enable_site_display" value="{{ __('Site') }}" />
                    <p id="enable_site_display" class="mt-1 text-sm font-semibold text-brand-ink">{{ $contextSite->name }}</p>
                </div>
            @else
                <div>
                    <x-input-label for="enable_site_id" value="{{ __('Site') }}" />
                    <select id="enable_site_id" wire:model.live="enable_site_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Pick a site…') }}</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($enableTargetSite === null)
                <p class="text-sm text-brand-moss">{{ __('Pick a site to see scheduler options for its detected stack.') }}</p>
            @elseif ($showLaravelSchedulerEnable)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="enable_framework_laravel" value="{{ __('Scheduler') }}" />
                        <p id="enable_framework_laravel" class="mt-1 text-sm text-brand-ink">{{ __('Laravel — `php artisan schedule:run`') }}</p>
                    </div>
                    <div>
                        <x-input-label for="enable_cron_expression" value="{{ __('Cadence') }}" />
                        <input id="enable_cron_expression" type="text" wire:model="enable_cron_expression" class="{{ $input }} mt-1 font-mono" placeholder="* * * * *" />
                    </div>
                </div>
            @elseif ($showRailsSchedulerEnable)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="enable_framework_rails" value="{{ __('Scheduler') }}" />
                        <p id="enable_framework_rails" class="mt-1 text-sm text-brand-ink">{{ __('Rails — `bundle exec whenever --update-crontab`') }}</p>
                    </div>
                    <div>
                        <x-input-label for="enable_cron_expression" value="{{ __('Cadence') }}" />
                        <input id="enable_cron_expression" type="text" wire:model="enable_cron_expression" class="{{ $input }} mt-1 font-mono" placeholder="* * * * *" />
                    </div>
                </div>
            @elseif ($showCustomSchedulerEnable)
                <div class="space-y-4">
                    <div>
                        <x-input-label for="enable_custom_command" value="{{ __('Scheduler command') }}" />
                        <textarea
                            id="enable_custom_command"
                            wire:model="enable_custom_command"
                            rows="3"
                            class="{{ $input }} mt-1 font-mono text-sm"
                            placeholder="cd /var/www/app/current && ./bin/cron"
                        ></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Bare shell command Dply wraps with dply-scheduler-tick. Include `cd` to the app directory if needed.') }}</p>
                    </div>
                    <div>
                        <x-input-label for="enable_cron_expression" value="{{ __('Cadence') }}" />
                        <input id="enable_cron_expression" type="text" wire:model="enable_cron_expression" class="{{ $input }} mt-1 font-mono" placeholder="* * * * *" />
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 pt-1">
                <button type="submit" class="{{ $btnPrimary }}" @disabled(! $opsReady || $enableTargetSite === null)>
                    {{ __('Enable scheduler') }}
                </button>
            </div>
        </form>

        @if ($showLaravelSchedulerEnable)
            <div class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-7">
                <p>{{ __('Prefer a long-running daemon? ') }}<a href="{{ route('servers.daemons', $server) }}?preset=laravel-schedule{{ $enableTargetSite ? '&site='.$enableTargetSite->id : '' }}" wire:navigate class="font-semibold text-brand-ink underline">{{ __('Add a schedule:work supervisor program') }}</a>{{ __(' instead.') }}</p>
            </div>
        @endif
    </section>
@endif
