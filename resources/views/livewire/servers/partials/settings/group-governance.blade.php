@php
    $environmentTypes = config('server_settings.environment_types', []);
    $dataRegionExamples = ['us-east-1', 'us-west-2', 'eu-west-1', 'eu-central-1', 'ap-southeast-1'];
    $complianceExamples = [
        'PCI scope' => "PCI scope: out — no cardholder data stored or processed on this host.",
        'SOC 2' => "SOC 2 in-scope. Access reviewed quarterly; audit logs shipped to central SIEM.",
        'GDPR' => "GDPR: EU data residency required. No transfer outside EEA without DPA review.",
        'HIPAA-adjacent' => "HIPAA-adjacent service; no PHI stored. Reviewed by security 2026-Q1.",
        'Internal only' => "Internal tooling. No customer PII; access limited to platform team.",
    ];
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
            {{ __('Rough costs and renewal reminders for your team. Pull the catalog price from your provider when supported, or type your own number — for example a negotiated annual commit, a parent-account sub-allocation, or a chargeback total that includes data transfer.') }}
        </p>
        <form wire:submit="saveCostLifecycle" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <div class="flex items-end justify-between gap-3">
                    <x-input-label for="settings-cost-note" value="{{ __('Monthly cost note') }}" />
                    @if ($this->canEditServerSettings)
                        @php $costPullSupported = $this->providerCostPullSupported(); @endphp
                        <button
                            type="button"
                            wire:click="pullCostFromProvider"
                            wire:loading.attr="disabled"
                            wire:target="pullCostFromProvider"
                            @disabled(! $costPullSupported)
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            title="{{ $costPullSupported
                                ? __('Fetch the current catalog price for this server\'s plan from the provider. The value lands in the box below; nothing is saved until you click Save cost notes.')
                                : __('Pulling cost from this provider is not yet supported, or this server has no linked credential / size on file.') }}"
                        >
                            <svg class="h-3.5 w-3.5" wire:loading.remove wire:target="pullCostFromProvider" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 4v5h5" />
                                <path d="M16 16v-5h-5" />
                                <path d="M5.5 9a6 6 0 0 1 10.4-2.5" />
                                <path d="M14.5 11a6 6 0 0 1-10.4 2.5" />
                            </svg>
                            <svg class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="pullCostFromProvider" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            <span wire:loading.remove wire:target="pullCostFromProvider">{{ __('Pull from provider') }}</span>
                            <span wire:loading wire:target="pullCostFromProvider">{{ __('Pulling…') }}</span>
                        </button>
                    @endif
                </div>
                <input
                    id="settings-cost-note"
                    type="text"
                    wire:model="settingsCostMonthlyNote"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. ~$48/mo on annual commit') }}"
                    @disabled(! $this->canEditServerSettings)
                />
                @if ($lastPulledCostEstimate)
                    <p class="mt-1.5 text-xs text-brand-moss">
                        {{ __('Pulled :currency :amount/mo for plan :plan from :provider on :fetched. Edit the field above to override before saving.', [
                            'currency' => $lastPulledCostEstimate['currency'],
                            'amount' => number_format((float) $lastPulledCostEstimate['monthly'], 2),
                            'plan' => $lastPulledCostEstimate['plan'],
                            'provider' => $lastPulledCostEstimate['provider_label'],
                            'fetched' => \Illuminate\Support\Carbon::parse($lastPulledCostEstimate['fetched_at'])->toDayDateTimeString(),
                        ]) }}
                    </p>
                    <dl class="mt-3 grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Catalog rate') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['monthly'], 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span>
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['hourly'], 4) }}/hr · {{ $lastPulledCostEstimate['currency'] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Estimated MTD') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['mtd'], 2) }}
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ __(':hours hrs this month', ['hours' => number_format((float) $lastPulledCostEstimate['runtime_hours_month'], 1)]) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Estimated YTD') }}</dt>
                            <dd class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $lastPulledCostEstimate['currency'] === 'USD' ? '$' : '' }}{{ number_format((float) $lastPulledCostEstimate['ytd'], 2) }}
                            </dd>
                            <dd class="text-xs text-brand-moss">
                                {{ __(':hours hrs this year', ['hours' => number_format((float) $lastPulledCostEstimate['runtime_hours_year'], 1)]) }}
                            </dd>
                        </div>
                        <div class="sm:col-span-3 text-xs text-brand-mist leading-relaxed">
                            {{ __('MTD and YTD are computed as catalog hourly rate × time this server has been alive in Dply. They exclude taxes, transfer overage, snapshots, volumes, and any negotiated discount. For the actual invoiced amount, open the provider console.') }}
                        </div>
                    </dl>
                @else
                    <p class="mt-1.5 text-xs text-brand-mist">
                        {{ __('Catalog price only — does not include taxes, data transfer overage, snapshots, or volume add-ons. Type your own value to record an actual or negotiated price. Pulling also computes runtime-based MTD and YTD estimates.') }}
                    </p>
                @endif
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
                @if ($this->canEditServerSettings)
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span class="text-xs text-brand-moss">{{ __('Examples:') }}</span>
                        @foreach ($dataRegionExamples as $example)
                            <button
                                type="button"
                                wire:click="$set('settingsDataRegion', @js($example))"
                                class="rounded-full border border-brand-ink/15 bg-white px-2.5 py-0.5 text-xs text-brand-ink hover:border-brand-sage hover:bg-brand-sage/10 focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >{{ $example }}</button>
                        @endforeach
                    </div>
                @endif
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
                @if ($this->canEditServerSettings)
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span class="text-xs text-brand-moss">{{ __('Insert example:') }}</span>
                        @foreach ($complianceExamples as $label => $text)
                            <button
                                type="button"
                                wire:click="$set('settingsComplianceNote', @js($text))"
                                class="rounded-full border border-brand-ink/15 bg-white px-2.5 py-0.5 text-xs text-brand-ink hover:border-brand-sage hover:bg-brand-sage/10 focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
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
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Treat this card as the index card on-call reaches for at 3 a.m.: a one-paragraph summary of how backups work, the targets you are committing to, and a link to the full recovery steps.') }}
        </p>

        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('What do RPO and RTO mean?') }}</p>
            <dl class="mt-2 space-y-2">
                <div>
                    <dt class="font-medium text-brand-ink">{{ __('RPO — Recovery Point Objective') }}</dt>
                    <dd class="mt-0.5">{{ __('The maximum amount of data, measured in time, you are willing to lose. An RPO of 24h means you accept losing up to a day of changes, so you need a usable backup at least that recent. Smaller RPO ⇒ more frequent snapshots, WAL shipping, or replication.') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-brand-ink">{{ __('RTO — Recovery Time Objective') }}</dt>
                    <dd class="mt-0.5">{{ __('The longest the service can be down before it must be back. An RTO of 4h means provisioning, restore, and smoke test all have to fit inside four hours. Smaller RTO ⇒ warm standbys, pre-built images, automated DNS failover.') }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-brand-mist">{{ __('Common shorthand: 15m, 1h, 4h, 24h, 7d. Pick targets your team can actually meet — auditors care more about an honest number than an aspirational one.') }}</p>
        </div>

        <form wire:submit="saveBackupDrHints" class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="settings-backup-strategy" value="{{ __('Strategy summary') }}" />
                <textarea
                    id="settings-backup-strategy"
                    wire:model="settingsBackupStrategy"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. Nightly pg_basebackup to s3://acme-backups/db/, 14-day retention, owned by platform team. WAL archived continuously.') }}"
                    @disabled(! $this->canEditServerSettings)
                ></textarea>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Plain English: what is backed up, how often, where it lives, who owns rotation, and how long it is retained.') }}</p>
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
                <p class="mt-1 text-xs text-brand-moss">{{ __('Most data loss you will tolerate. Match it to backup frequency.') }}</p>
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
                <p class="mt-1 text-xs text-brand-moss">{{ __('Most downtime you will tolerate. Time the runbook end-to-end at least once a year.') }}</p>
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
                <p class="mt-1 text-xs text-brand-moss">{{ __('Link to step-by-step recovery instructions (Notion, Confluence, GitHub, etc.). On-call should be able to follow it without prior knowledge of this host.') }}</p>
                <x-input-error :messages="$errors->get('settingsBackupRunbookUrl')" class="mt-2" />
            </div>
            @if ($this->canEditServerSettings)
                <div class="sm:col-span-2 flex justify-end">
                    <x-primary-button type="submit" wire:loading.attr="disabled">{{ __('Save backup & DR') }}</x-primary-button>
                </div>
            @endif
        </form>

        <details class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            <summary class="cursor-pointer font-medium text-brand-ink">{{ __('Sample runbooks (templates to copy and adapt)') }}</summary>
            <p class="mt-2 text-xs text-brand-mist">{{ __('Paste one of these into your wiki, fill in the host-specific details, then drop the link into the Runbook URL field above.') }}</p>
            <div class="mt-4 space-y-5">
                <div>
                    <p class="font-medium text-brand-ink">{{ __('1. Stateless web app behind a load balancer') }}</p>
                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                        <li>{{ __('Confirm the config repo and secret store are reachable; note the last green deploy SHA.') }}</li>
                        <li>{{ __('Provision a replacement server in Dply using the same workspace, region, and provisioning recipe.') }}</li>
                        <li>{{ __('Re-deploy the last green release from CI; wait for the health check to pass.') }}</li>
                        <li>{{ __('Add the new host to the load balancer, remove the failed one, then verify traffic is shifting.') }}</li>
                        <li>{{ __('Smoke test the homepage + auth flow; post a recovery summary to the on-call channel.') }}</li>
                    </ol>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Typical targets — RPO 0 (no local state) · RTO 30–60m') }}</p>
                </div>
                <div>
                    <p class="font-medium text-brand-ink">{{ __('2. Single-host PostgreSQL with nightly snapshots') }}</p>
                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                        <li>{{ __('Put the application in maintenance mode (or stop the writers).') }}</li>
                        <li>{{ __('Open the provider console, locate the most recent verified snapshot, and capture its ID.') }}</li>
                        <li>{{ __('Restore that snapshot to a new volume / instance; record the new private IP.') }}</li>
                        <li>{{ __('If WAL archiving is enabled, replay segments up to the desired recovery point.') }}</li>
                        <li>{{ __('Run a sanity query (row counts on the top 3 tables) before re-pointing traffic.') }}</li>
                        <li>{{ __('Update DATABASE_URL, lift maintenance mode, and trigger a fresh snapshot once stable.') }}</li>
                    </ol>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Typical targets — RPO 24h (or minutes with WAL shipping) · RTO 2–4h') }}</p>
                </div>
                <div>
                    <p class="font-medium text-brand-ink">{{ __('3. Worker / queue host with object-storage state') }}</p>
                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                        <li>{{ __('Pause the queue producer or scale workers to zero so jobs stop dequeuing.') }}</li>
                        <li>{{ __('Provision a replacement host with the saved provisioning recipe.') }}</li>
                        <li>{{ __('Re-sync the working directory from object storage (e.g. aws s3 sync).') }}</li>
                        <li>{{ __('Start the worker, watch the queue depth drain, and confirm logs are clean.') }}</li>
                        <li>{{ __('Reconcile in-flight jobs that may have been retried while the host was down.') }}</li>
                    </ol>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Typical targets — RPO 1h · RTO 1–2h') }}</p>
                </div>
                <div>
                    <p class="font-medium text-brand-ink">{{ __('4. Full host loss (provider region outage)') }}</p>
                    <ol class="mt-1 list-decimal pl-5 space-y-1">
                        <li>{{ __('Declare the incident, assign an IC, and open the on-call bridge.') }}</li>
                        <li>{{ __('Confirm the failure scope with the provider status page; do not assume single-host failure.') }}</li>
                        <li>{{ __('Spin up the replacement in the designated DR region from the latest cross-region backup.') }}</li>
                        <li>{{ __('Update DNS / Anycast / CDN to point at the DR region; lower TTLs in advance if possible.') }}</li>
                        <li>{{ __('Once the primary region is healthy, plan a controlled fail-back during a maintenance window.') }}</li>
                    </ol>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Typical targets — RPO 1–24h · RTO 4–24h (depends on whether DR is warm or cold)') }}</p>
                </div>
            </div>
        </details>
    </div>
</section>
