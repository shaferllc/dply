@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $summary = $report['summary'] ?? [];
    $ruleRows = $report['rule_rows'] ?? [];
    $policyTz = $summary['timezone'] ?? config('app.timezone');

    $dayLabels = [
        'mon' => __('Mon'), 'tue' => __('Tue'), 'wed' => __('Wed'), 'thu' => __('Thu'),
        'fri' => __('Fri'), 'sat' => __('Sat'), 'sun' => __('Sun'),
    ];
@endphp

<div>
    {{-- The verdict + reason + rule count + timezone stack that used to open
         this tab said exactly what the head pill and the enforcement banner
         directly above already say — three copies of one fact. The figures go
         straight into a strip; Policy on/off is the banner's whole message, so
         it doesn't get a cell of its own. --}}
    <x-workspace-stat-strip
        class="border-b border-brand-ink/10"
        :stats="[
            [
                'label' => __('Deny rules'),
                'value' => number_format((int) ($summary['rule_count'] ?? 0)),
            ],
            [
                'label' => __('Active now'),
                'value' => number_format((int) ($summary['active_rules_now'] ?? 0)),
                'tone' => ($summary['active_rules_now'] ?? 0) > 0 ? 'warn' : null,
            ],
            [
                'label' => __('Sites covered'),
                'value' => number_format((int) ($summary['total_sites'] ?? 0)),
            ],
            [
                'label' => __('Skipped (7d)'),
                'value' => number_format((int) ($summary['skipped_deploys_7d'] ?? 0)),
                'tone' => ($summary['skipped_deploys_7d'] ?? 0) > 0 ? 'warn' : null,
            ],
        ]"
    />

    @if ($canUpdate)
        {{-- Editor (absorbs the old Schedule tab). --}}
        <section>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-adjustments-horizontal"
                :title="__('Edit policy')"
                :note="__('Toggle enforcement, set timezone + skip message, and manage deny rules. Save to apply server-wide.')"
                class="border-b border-brand-ink/10"
            />

            <form wire:submit="savePolicy" class="space-y-3.5 px-4 py-3.5 sm:px-5">
                <label class="inline-flex items-center gap-2 text-xs font-semibold text-brand-ink">
                    <input type="checkbox" wire:model.live="policy_enabled" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                    {{ __('Enable deploy window policy') }}
                </label>

                <div class="grid gap-3 lg:grid-cols-2">
                    <div>
                        <x-input-label for="policy_timezone" :value="__('Timezone')" />
                        <x-text-input id="policy_timezone" wire:model="policy_timezone" class="mt-1 block w-full max-w-xs" />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Deny windows are evaluated in this timezone.') }}</p>
                        <x-input-error :messages="$errors->get('policy_timezone')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="policy_message" :value="__('Skip message')" />
                        <textarea id="policy_message" wire:model="policy_message" rows="2" maxlength="500" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30" placeholder="{{ __('Logged when a deploy is skipped — e.g. Weekend freeze active') }}"></textarea>
                        <x-input-error :messages="$errors->get('policy_message')" class="mt-1" />
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Deny windows') }}</h3>
                        <div class="flex gap-1.5">
                            <button type="button" wire:click="applyWeekendFreezePreset" class="inline-flex h-6 items-center rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">{{ __('Weekend freeze preset') }}</button>
                            <button type="button" wire:click="addDenyRule" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ __('Add rule') }}
                            </button>
                        </div>
                    </div>

                    @forelse ($deny_rules as $index => $rule)
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/10 px-3 py-2.5" wire:key="deny-rule-{{ $index }}">
                            <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                                <div class="min-w-[12rem] flex-1">
                                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Days') }}</p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($dayOptions as $day)
                                            <label class="inline-flex items-center gap-1 rounded-md border border-brand-ink/10 bg-white px-1.5 py-0.5 text-xs">
                                                <input type="checkbox" value="{{ $day }}" wire:model="deny_rules.{{ $index }}.days" class="h-3 w-3 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                                {{ $dayLabels[$day] ?? strtoupper($day) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Start') }}</p>
                                    <input type="time" wire:model="deny_rules.{{ $index }}.start" class="mt-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                                </div>
                                <div>
                                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('End') }}</p>
                                    <input type="time" wire:model="deny_rules.{{ $index }}.end" class="mt-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs">
                                </div>
                                <button type="button" wire:click="removeDenyRule({{ $index }})" class="ml-auto text-xs font-semibold text-rose-700 hover:text-rose-900">{{ __('Remove') }}</button>
                            </div>
                        </div>
                    @empty
                        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/15 px-3 py-2 text-xs text-brand-moss">
                            <x-heroicon-m-calendar-days class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            {{ __('No deny rules — deploys are never blocked by schedule until you add one.') }}
                        </p>
                    @endforelse
                </div>

                <x-primary-button type="submit">{{ __('Save policy') }}</x-primary-button>
            </form>
        </section>
    @else
        {{-- Read-only view for operators without `update` on this server. --}}
        <section>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-clock"
                :title="__('Configured deny windows')"
                :count="count($ruleRows) > 0 ? count($ruleRows) : null"
                :note="__('Read-only access. Each rule blocks deploys on selected weekdays between start and end, in the policy timezone.')"
                class="border-b border-brand-ink/10"
            />

            @if ($ruleRows === [])
                <x-empty-state
                    borderless
                    compact
                    icon="heroicon-o-calendar-days"
                    :title="__('No deny rules configured')"
                    :description="__('Deploys on this server are never blocked by schedule.')"
                />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                        <thead class="bg-brand-sand/20 text-left text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">
                            <tr>
                                <th scope="col" class="px-4 py-1.5 sm:px-5">{{ __('Schedule') }}</th>
                                <th scope="col" class="px-3 py-1.5">{{ __('Days') }}</th>
                                <th scope="col" class="px-3 py-1.5">{{ __('Start') }}</th>
                                <th scope="col" class="px-3 py-1.5">{{ __('End') }}</th>
                                <th scope="col" class="px-4 py-1.5 sm:px-5">{{ __('Now') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 bg-white">
                            @foreach ($ruleRows as $row)
                                <tr wire:key="policy-rule-{{ $row['index'] }}">
                                    <td class="px-4 py-1.5 font-semibold text-brand-ink sm:px-5">{{ $row['summary'] }}</td>
                                    <td class="px-3 py-1.5 text-brand-moss">{{ $row['days_label'] }}</td>
                                    <td class="px-3 py-1.5 font-mono tabular-nums">{{ $row['start'] }}</td>
                                    <td class="px-3 py-1.5 font-mono tabular-nums">
                                        {{ $row['end'] }}
                                        @if ($row['overnight'])
                                            <span class="ms-1 text-2xs font-semibold uppercase text-brand-mist">{{ __('overnight') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-1.5 sm:px-5">
                                        @if ($row['active_now'])
                                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold ring-1 {{ $tonePalette['amber'] }}">{{ __('Blocking') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold ring-1 {{ $tonePalette['mist'] }}">{{ __('Idle') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
