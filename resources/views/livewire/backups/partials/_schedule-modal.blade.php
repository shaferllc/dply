{{-- Create or retime a BackupSchedule from a Backups type tab. Backed by the
     EditsBackupSchedules trait; include from any tab that lists schedules. --}}
@if ($showScheduleModal)
    <div
        class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="schedule-modal-title"
        x-data
        x-on:keydown.escape.window="$wire.closeScheduleModal()"
    >
        <div class="fixed inset-0 bg-brand-ink/30" wire:click="closeScheduleModal"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div class="my-auto flex w-full max-w-xl flex-col dply-modal-panel overflow-hidden shadow-xl" @click.stop>
                <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Schedule') }}</p>
                        <h2 id="schedule-modal-title" class="mt-1 text-lg font-semibold text-brand-ink">
                            {{ $editing_schedule_id ? __('Edit schedule') : __('Create schedule') }}
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Runs from dply, not cron on your server — so a capture still happens when the box is unreachable.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="closeScheduleModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-6">
                    <div>
                        <x-input-label for="schedule_cadence" :value="__('How often')" />
                        <select
                            id="schedule_cadence"
                            wire:model.live="scheduleForm.cadence"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        >
                            @foreach ($this->scheduleCadenceOptions() as $expression => $label)
                                <option value="{{ $expression }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if (($scheduleForm['cadence'] ?? '') === 'custom')
                        <div>
                            <x-input-label for="schedule_cron" :value="__('Cron expression')" />
                            <x-text-input
                                id="schedule_cron"
                                type="text"
                                class="mt-1 block w-full font-mono"
                                autocomplete="off"
                                placeholder="0 3 * * *"
                                wire:model="scheduleForm.cron_expression"
                            />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Five fields: minute, hour, day of month, month, day of week. Times are UTC.') }}</p>
                            <x-input-error :messages="$errors->get('scheduleForm.cron_expression')" class="mt-2" />
                        </div>
                    @endif

                    <div>
                        <x-input-label for="schedule_destination" :value="__('Ship to')" />
                        <select
                            id="schedule_destination"
                            wire:model="scheduleForm.backup_configuration_id"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        >
                            <option value="">{{ __('Server default — keep the dump on the server') }}</option>
                            @foreach ($this->scheduleDestinationOptions() as $destination)
                                <option value="{{ $destination->id }}">
                                    {{ $destination->name }} — {{ \App\Models\BackupConfiguration::labelForProvider($destination->provider) }}
                                </option>
                            @endforeach
                        </select>
                        @if ($this->scheduleDestinationOptions()->isEmpty())
                            {{-- Without a destination the dump never leaves the machine
                                 it is protecting, which is not a backup in the sense
                                 most people mean. --}}
                            <p class="mt-1.5 text-xs text-brand-moss">
                                {{ __('No destinations yet — a dump kept on the same server dies with it.') }}
                                <a href="{{ route('backups.storage') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Add one') }}</a>
                            </p>
                        @endif
                        <x-input-error :messages="$errors->get('scheduleForm.backup_configuration_id')" class="mt-2" />
                    </div>

                    <label class="flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3">
                        <input type="checkbox" wire:model="scheduleForm.notify_on_failure" class="mt-0.5 h-4 w-4 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-brand-ink">{{ __('Tell me when a run fails') }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('A backup that stopped working is only useful to know about before you need it.') }}</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3">
                        <input type="checkbox" wire:model="scheduleForm.is_active" class="mt-0.5 h-4 w-4 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-brand-ink">{{ __('Active') }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Paused schedules keep their settings and their history, they just stop firing.') }}</span>
                        </span>
                    </label>
                </div>

                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    @if ($editing_schedule_id)
                        <button
                            type="button"
                            wire:click="deleteSchedule('{{ $editing_schedule_id }}')"
                            wire:confirm="{{ __('Remove this schedule? Nothing will back this target up automatically afterwards.') }}"
                            class="text-xs font-semibold text-rose-700 hover:text-rose-900"
                        >
                            {{ __('Delete schedule') }}
                        </button>
                    @else
                        <span></span>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        <x-secondary-button type="button" wire:click="closeScheduleModal">{{ __('Cancel') }}</x-secondary-button>
                        <button
                            type="button"
                            wire:click="saveSchedule"
                            wire:loading.attr="disabled"
                            wire:target="saveSchedule"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="saveSchedule" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $editing_schedule_id ? __('Save schedule') : __('Create schedule') }}
                            </span>
                            <span wire:loading wire:target="saveSchedule" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
