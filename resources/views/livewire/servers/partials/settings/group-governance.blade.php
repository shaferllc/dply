@php
    $environmentTypes = config('server_settings.environment_types', []);
@endphp

<section id="settings-group-governance" class="space-y-6" aria-labelledby="settings-group-governance-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-governance-title',
        'kicker' => __('Governance'),
        'title' => __('Cost, environment & backups'),
        'description' => __('Documentation for finance, compliance, and recovery. Nothing here bills your cloud account or runs backups—use it so operators know where to look.'),
    ])

    <div id="settings-cost" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Cost & lifecycle') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Rough costs and renewal reminders for your team. Not synchronized from the provider API.') }}
        </p>
        <form wire:submit="saveCostLifecycle" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="settings-cost-note" value="{{ __('Monthly cost note') }}" />
                <input
                    id="settings-cost-note"
                    type="text"
                    wire:model="settingsCostMonthlyNote"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. ~$48/mo on annual commit') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsCostMonthlyNote')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-cost-renewal" value="{{ __('Renewal / review date') }}" />
                <input
                    id="settings-cost-renewal"
                    type="date"
                    wire:model="settingsCostRenewalDate"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsCostRenewalDate')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-cost-url" value="{{ __('Provider console URL') }}" />
                <input
                    id="settings-cost-url"
                    type="url"
                    wire:model="settingsCostProviderUrl"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="https://"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsCostProviderUrl')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save cost notes') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>

    <div id="settings-compliance" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Environment & compliance') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Classify the server for policy reviews. Labels are visible in Dply only unless you export them.') }}
        </p>
        <form wire:submit="saveComplianceSettings" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="settings-env-type" value="{{ __('Environment') }}" />
                <select
                    id="settings-env-type"
                    wire:model="settingsEnvType"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                >
                    @foreach ($environmentTypes as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('settingsEnvType')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-data-region" value="{{ __('Data region label') }}" />
                <input
                    id="settings-data-region"
                    type="text"
                    wire:model="settingsDataRegion"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. EU-West, us-east-1') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsDataRegion')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="settings-compliance-note" value="{{ __('Compliance notes') }}" />
                <textarea
                    id="settings-compliance-note"
                    wire:model="settingsComplianceNote"
                    rows="4"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsComplianceNote')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save compliance') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>

    <div id="settings-backup" class="{{ $card }} scroll-mt-24 p-6 sm:p-8">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Backup & disaster recovery') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Describe how data is protected and how you would restore this host. Dply does not execute backups from these fields—they are for operators and auditors.') }}
        </p>
        <form wire:submit="saveBackupDrHints" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="settings-backup-strategy" value="{{ __('Strategy summary') }}" />
                <textarea
                    id="settings-backup-strategy"
                    wire:model="settingsBackupStrategy"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <x-input-error :messages="$errors->get('settingsBackupStrategy')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-backup-rpo" value="{{ __('RPO (target)') }}" />
                <input
                    id="settings-backup-rpo"
                    type="text"
                    wire:model="settingsBackupRpo"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. 24h') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsBackupRpo')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="settings-backup-rto" value="{{ __('RTO (target)') }}" />
                <input
                    id="settings-backup-rto"
                    type="text"
                    wire:model="settingsBackupRto"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. 4h') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsBackupRto')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="settings-backup-runbook" value="{{ __('Runbook URL') }}" />
                <input
                    id="settings-backup-runbook"
                    type="url"
                    wire:model="settingsBackupRunbookUrl"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="https://"
                    @disabled(! $this->canEditServerSettings)
                />
                <x-input-error :messages="$errors->get('settingsBackupRunbookUrl')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save backup & DR') }}</x-primary-button>
                </div>
            @endif
        </form>
    </div>
</section>
