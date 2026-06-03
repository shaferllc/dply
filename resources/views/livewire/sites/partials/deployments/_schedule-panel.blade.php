@php $schedules = $site->deploymentSchedules; @endphp

<div class="border-b border-brand-ink/10 bg-white px-6 py-5 sm:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Scheduled deploys') }}</p>
            <p class="mt-0.5 text-sm text-brand-moss">
                {{ __('Deploy the current branch automatically on a cron cadence. Runs on the dply scheduler — no server crontab needed.') }}
            </p>
        </div>
        @unless ($show_add_schedule_form)
            <button
                type="button"
                wire:click="openAddScheduleForm"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-clock class="h-3.5 w-3.5" />
                {{ __('Add schedule') }}
            </button>
        @endunless
    </div>

    @if ($schedules->isNotEmpty())
        <ul class="mt-4 space-y-2">
            @foreach ($schedules as $schedule)
                <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                    <div class="flex min-w-0 items-center gap-3">
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200' => $schedule->is_active,
                            'bg-brand-sand/60 text-brand-moss ring-brand-ink/10' => ! $schedule->is_active,
                        ])>{{ $schedule->is_active ? __('Active') : __('Paused') }}</span>
                        <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs text-brand-ink ring-1 ring-brand-ink/10">{{ $schedule->cron_expression }}</code>
                        <span class="truncate text-[11px] text-brand-mist">
                            @if ($schedule->last_run_at)
                                {{ __('last run :time', ['time' => $schedule->last_run_at->diffForHumans()]) }}
                            @else
                                {{ __('not run yet') }}
                            @endif
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button type="button" wire:click="runDeploymentScheduleNow('{{ $schedule->id }}')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Run this deploy now') }}">
                            <x-heroicon-o-rocket-launch class="h-3.5 w-3.5" />
                            {{ __('Run now') }}
                        </button>
                        <button type="button" wire:click="toggleDeploymentSchedule('{{ $schedule->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40">
                            {{ $schedule->is_active ? __('Pause') : __('Resume') }}
                        </button>
                        <button type="button" wire:click="deleteDeploymentSchedule('{{ $schedule->id }}')" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50" title="{{ __('Remove schedule') }}">
                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($show_add_schedule_form)
        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="new_schedule_preset" :value="__('Cadence')" />
                    <select id="new_schedule_preset" wire:model.live="new_schedule_preset" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        @foreach ($this->scheduleCronPresets() as $key => $preset)
                            <option value="{{ $key }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="new_schedule_cron" :value="__('Cron expression')" />
                    <x-text-input id="new_schedule_cron" wire:model="new_schedule_cron" class="mt-1 block w-full font-mono text-sm" placeholder="0 3 * * *" :disabled="$new_schedule_preset !== 'custom'" />
                    <x-input-error :messages="$errors->get('new_schedule_cron')" class="mt-1" />
                </div>
            </div>
            <label class="mt-3 flex items-center gap-2 text-sm text-brand-ink">
                <input type="checkbox" wire:model="new_schedule_notify" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                {{ __('Notify me if a scheduled deploy fails') }}
            </label>
            <div class="mt-3 flex items-center gap-2">
                <button type="button" wire:click="addDeploymentSchedule" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                    {{ __('Add schedule') }}
                </button>
                <button type="button" wire:click="closeAddScheduleForm" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
            </div>
        </div>
    @endif
</div>
