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

<div class="mt-6 space-y-6">
    {{-- Stat strip (absorbs the old Overview tab). --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy window policy') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                    @switch($overall)
                        @case('blocked') {{ __('Deploys blocked now') }} @break
                        @case('allowed') {{ __('Deploys allowed now') }} @break
                        @default {{ __('Policy disabled') }}
                    @endswitch
                </h2>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($overall === 'disabled')
                        {{ __('Enable the policy below to enforce deny windows for every site on this server.') }}
                    @elseif (! $currentAllowed && $blockReason)
                        {{ $blockReason }}
                        @if ($nextAllowedAt)
                            · {{ __('Allowed again :time', ['time' => $nextAllowedAt->timezone($policyTz)->format('D H:i T')]) }}
                        @endif
                    @else
                        {{ trans_choice(':count deny rule configured|:count deny rules configured', $summary['rule_count'] ?? 0, ['count' => $summary['rule_count'] ?? 0]) }}
                        · {{ __('Timezone :tz', ['tz' => $policyTz]) }}
                    @endif
                </p>
            </div>
        </div>

        <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['label' => __('Policy'), 'value' => ($summary['enabled'] ?? false) ? __('On') : __('Off')],
                ['label' => __('Deny rules'), 'value' => number_format((int) ($summary['rule_count'] ?? 0))],
                ['label' => __('Active now'), 'value' => number_format((int) ($summary['active_rules_now'] ?? 0))],
                ['label' => __('Sites covered'), 'value' => number_format((int) ($summary['total_sites'] ?? 0))],
                ['label' => __('Skipped (7d)'), 'value' => number_format((int) ($summary['skipped_deploys_7d'] ?? 0))],
            ] as $stat)
                <div class="bg-white px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $stat['label'] }}</p>
                    <p class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($canUpdate)
        {{-- Editor (absorbs the old Schedule tab). --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Editor') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edit policy') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Toggle enforcement, set timezone + skip message, and manage deny rules. Save to apply server-wide.') }}</p>
                </div>
            </div>

            <form wire:submit="savePolicy" class="space-y-5 p-6 sm:p-7">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-brand-ink">
                    <input type="checkbox" wire:model.live="policy_enabled" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                    {{ __('Enable deploy window policy') }}
                </label>

                <div class="grid gap-5 lg:grid-cols-2">
                    <div>
                        <x-input-label for="policy_timezone" :value="__('Timezone')" />
                        <x-text-input id="policy_timezone" wire:model="policy_timezone" class="mt-1 block w-full max-w-xs" />
                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Deny windows are evaluated in this timezone.') }}</p>
                        <x-input-error :messages="$errors->get('policy_timezone')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="policy_message" :value="__('Skip message')" />
                        <textarea id="policy_message" wire:model="policy_message" rows="2" maxlength="500" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30" placeholder="{{ __('Logged when a deploy is skipped — e.g. Weekend freeze active') }}"></textarea>
                        <x-input-error :messages="$errors->get('policy_message')" class="mt-1" />
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Deny windows') }}</h3>
                        <div class="flex gap-2">
                            <button type="button" wire:click="applyWeekendFreezePreset" class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Weekend freeze preset') }}</button>
                            <button type="button" wire:click="addDenyRule" class="rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Add rule') }}</button>
                        </div>
                    </div>

                    @forelse ($deny_rules as $index => $rule)
                        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4" wire:key="deny-rule-{{ $index }}">
                            <div class="flex flex-wrap gap-4">
                                <div class="min-w-[12rem] flex-1">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Days') }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($dayOptions as $day)
                                            <label class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-xs">
                                                <input type="checkbox" value="{{ $day }}" wire:model="deny_rules.{{ $index }}.days" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                                {{ $dayLabels[$day] ?? strtoupper($day) }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Start') }}</p>
                                    <input type="time" wire:model="deny_rules.{{ $index }}.start" class="mt-2 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-sm">
                                </div>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('End') }}</p>
                                    <input type="time" wire:model="deny_rules.{{ $index }}.end" class="mt-2 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-sm">
                                </div>
                                <button type="button" wire:click="removeDenyRule({{ $index }})" class="self-end text-xs font-semibold text-rose-700 hover:text-rose-900">{{ __('Remove') }}</button>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-6 text-center text-sm text-brand-moss">{{ __('No deny rules — deploys are never blocked by schedule until you add one.') }}</p>
                    @endforelse
                </div>

                <x-primary-button type="submit">{{ __('Save policy') }}</x-primary-button>
            </form>
        </section>
    @else
        {{-- Read-only view for operators without `update` on this server. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deny windows') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Configured deny windows') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('You have read-only access to this policy. Each rule blocks deploys on selected weekdays between start and end (server policy timezone).') }}</p>
                </div>
            </div>

            @if ($ruleRows === [])
                <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-7">{{ __('No deny rules configured.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/20 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">
                            <tr>
                                <th scope="col" class="px-6 py-3">{{ __('Schedule') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Days') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('Start') }}</th>
                                <th scope="col" class="px-4 py-3">{{ __('End') }}</th>
                                <th scope="col" class="px-6 py-3">{{ __('Now') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/8 bg-white">
                            @foreach ($ruleRows as $row)
                                <tr wire:key="policy-rule-{{ $row['index'] }}">
                                    <td class="px-6 py-3.5 font-medium text-brand-ink">{{ $row['summary'] }}</td>
                                    <td class="px-4 py-3.5 text-brand-moss">{{ $row['days_label'] }}</td>
                                    <td class="px-4 py-3.5 font-mono text-xs">{{ $row['start'] }}</td>
                                    <td class="px-4 py-3.5 font-mono text-xs">
                                        {{ $row['end'] }}
                                        @if ($row['overnight'])
                                            <span class="ms-1 text-[10px] font-semibold uppercase text-brand-mist">{{ __('overnight') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3.5">
                                        @if ($row['active_now'])
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['amber'] }}">{{ __('Blocking') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['mist'] }}">{{ __('Idle') }}</span>
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
