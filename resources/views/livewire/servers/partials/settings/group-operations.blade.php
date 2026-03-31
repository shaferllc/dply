@php
    $maintenanceWeekdays = config('server_settings.maintenance_weekdays', []);
@endphp

<section id="settings-group-ops" class="space-y-6" aria-labelledby="settings-group-ops-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-ops-title',
        'kicker' => __('Operations'),
        'title' => __('Alerts & planned downtime'),
        'description' => __('Optional fields your team can use for on-call context and maintenance planning. They do not change how Dply deploys today; future scheduling features may read them.'),
    ])

    <div id="settings-maintenance" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Maintenance window') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('When you prefer disruptive work (upgrades, reboots). Times use your Dply timezone preference in the “Timezone & notes” section below, not the server OS clock.') }}
        </p>
        <form wire:submit="saveMaintenanceWindow" class="mt-6 space-y-5">
            <fieldset @disabled(! $this->canEditServerSettings)>
                <legend class="text-sm font-medium text-brand-ink">{{ __('Preferred days') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach ($maintenanceWeekdays as $key => $label)
                        <label class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-sm">
                            <input type="checkbox" wire:model="settingsMaintenanceDays" value="{{ $key }}" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('settingsMaintenanceDays')" class="mt-2" />
            </fieldset>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="settings-maint-start" value="{{ __('Start (local)') }}" />
                    <input
                        id="settings-maint-start"
                        type="time"
                        wire:model="settingsMaintenanceStart"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceStart')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="settings-maint-end" value="{{ __('End (local)') }}" />
                    <input
                        id="settings-maint-end"
                        type="time"
                        wire:model="settingsMaintenanceEnd"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                        @disabled(! $this->canEditServerSettings)
                    />
                    <x-input-error :messages="$errors->get('settingsMaintenanceEnd')" class="mt-2" />
                </div>
            </div>
            <div>
                <x-input-label for="settings-maint-note" value="{{ __('Note') }}" />
                <textarea
                    id="settings-maint-note"
                    wire:model="settingsMaintenanceNote"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsMaintenanceNote')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save maintenance window') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>

    <div id="settings-notifications" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Notification routing') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Free-form hints for escalations (Slack, PagerDuty, phone tree). Organization-wide notification rules still apply; this is per-server context.') }}
        </p>
        <form wire:submit="saveNotificationRouting" class="mt-6 space-y-5">
            <div>
                <x-input-label for="settings-notif-note" value="{{ __('Routing notes') }}" />
                <textarea
                    id="settings-notif-note"
                    wire:model="settingsNotifRoutingNote"
                    rows="4"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('Escalation path, Slack channel, PagerDuty service, …') }}"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsNotifRoutingNote')" class="mt-2" />
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-brand-ink">
                <input type="checkbox" wire:model="settingsNotifHealthAlerts" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage" @disabled(! $this->canEditServerSettings) />
                {{ __('Flag this server when discussing health incidents') }}
            </label>
            @if ($this->canEditServerSettings)
                <div class="flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save notification hints') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>
</section>
