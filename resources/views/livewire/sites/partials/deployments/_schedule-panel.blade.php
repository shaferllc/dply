{{-- The Schedule tab: RECURRING cron-cadence deploys. The Deploy tab's
     "Deploy later" dropdown is the one-off equivalent and stays there. --}}
@php $schedules = $site->deploymentSchedules; @endphp

<x-workspace-panel-head
    dense
    class="border-b border-brand-ink/10"
    icon="heroicon-o-calendar-days"
    :title="__('Recurring deploys')"
    :count="trans_choice('{0} no schedules|{1} :count schedule|[2,*] :count schedules', $schedules->count(), ['count' => $schedules->count()])"
    :note="__('Cron-cadence deploys on the dply scheduler. For a one-off delay, use “Deploy later” on the Deploy tab.')"
>
    @unless ($show_add_schedule_form)
        <x-slot:actions>
            <button
                type="button"
                wire:click="openAddScheduleForm"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                {{ __('Add schedule') }}
            </button>
        </x-slot:actions>
    @endunless
</x-workspace-panel-head>

<div class="border-b border-brand-ink/10 bg-white px-3 py-2.5 sm:px-4">
    @if ($schedules->isNotEmpty())
        <ul class="space-y-1.5">
            @foreach ($schedules as $schedule)
                <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-2.5 py-2 transition-colors hover:border-brand-ink/15">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span @class([
                            'h-2 w-2 shrink-0 rounded-full',
                            'bg-emerald-500' => $schedule->is_active,
                            'bg-brand-mist' => ! $schedule->is_active,
                        ]) title="{{ $schedule->is_active ? __('Active') : __('Paused') }}"></span>
                        <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs text-brand-ink ring-1 ring-inset ring-brand-ink/10">{{ $schedule->cron_expression }}</code>
                        @if ($cronDesc = $schedule->cronDescription())
                            <span class="truncate text-xs text-brand-mist">{{ $cronDesc }}</span>
                        @endif
                        <span class="truncate text-xs text-brand-mist">
                            @if ($schedule->last_run_at)
                                {{ __('last run :time', ['time' => $schedule->last_run_at->diffForHumans()]) }}
                            @else
                                {{ __('not run yet') }}
                            @endif
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button type="button" wire:click="runDeploymentScheduleNow('{{ $schedule->id }}')" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40" title="{{ __('Run this deploy now') }}">
                            <x-heroicon-o-rocket-launch class="h-4 w-4" />
                            {{ __('Run now') }}
                        </button>
                        <button type="button" wire:click="toggleDeploymentSchedule('{{ $schedule->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40">
                            {{ $schedule->is_active ? __('Pause') : __('Resume') }}
                        </button>
                        <button type="button" wire:click="deleteDeploymentSchedule('{{ $schedule->id }}')" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove schedule') }}">
                            <x-heroicon-o-trash class="h-4 w-4" />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @elseif (! $show_add_schedule_form)
        <p class="mt-2 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/15 px-3 py-2 text-xs text-brand-mist">
            {{ __('No schedules yet — add one to deploy this branch on a recurring cadence.') }}
        </p>
    @endif

    @if ($show_add_schedule_form)
        <div class="mt-2 rounded-lg border border-brand-ink/10 bg-brand-cream/40 p-3">
            <div class="grid gap-2 sm:grid-cols-2">
                <div>
                    <x-input-label for="new_schedule_preset" :value="__('Cadence')" />
                    <select id="new_schedule_preset" wire:model.live="new_schedule_preset" class="mt-0.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        @foreach ($this->scheduleCronPresets() as $key => $preset)
                            <option value="{{ $key }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="new_schedule_cron" :value="__('Cron expression')" />
                    <x-text-input id="new_schedule_cron" wire:model="new_schedule_cron" class="mt-0.5 block w-full font-mono text-xs" placeholder="0 3 * * *" :disabled="$new_schedule_preset !== 'custom'" />
                    <x-input-error :messages="$errors->get('new_schedule_cron')" class="mt-1" />
                </div>
            </div>
            <label class="mt-2 flex items-center gap-2 text-xs text-brand-ink">
                <input type="checkbox" wire:model="new_schedule_notify" class="h-3.5 w-3.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                {{ __('Notify me if a scheduled deploy fails') }}
            </label>
            <div class="mt-2 flex items-center gap-2">
                <button type="button" wire:click="addDeploymentSchedule" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                    <x-heroicon-o-check class="h-3.5 w-3.5" />
                    {{ __('Add schedule') }}
                </button>
                <button type="button" wire:click="closeAddScheduleForm" class="text-xs font-semibold text-brand-moss transition-colors hover:text-brand-ink">{{ __('Cancel') }}</button>
            </div>
        </div>
    @endif
</div>
