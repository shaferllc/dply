    @if ($switch_plan !== null || $switch_preflight_target !== null)
        @php
            $switchTargetLabel = $switch_plan !== null
                ? (string) ($switch_plan['to'] ?? $switch_preflight_target)
                : (string) $switch_preflight_target;
            $switchFromLabel = $switch_plan !== null
                ? (string) ($switch_plan['from'] ?? '—')
                : strtolower(trim((string) ($server->meta['webserver'] ?? 'nginx')));
            $switchPlanLoading = $switch_plan === null && $switch_preflight_target !== null;
        @endphp
        <x-modal
            name="webserver-switch-modal"
            maxWidth="2xl"
            overlayClass="bg-brand-ink/40"
            panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        >
            @if ($switchPlanLoading)
                <div wire:init="loadSwitchPlan" class="hidden" aria-hidden="true"></div>
            @endif
            <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Confirm switch') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Switch webserver?') }}</h2>
                <div class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 font-mono text-sm text-brand-ink">
                    <span>{{ $switchFromLabel }}</span>
                    <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0 text-brand-mist" />
                    <span>{{ $switchTargetLabel }}</span>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                @if ($switchPlanLoading)
                    <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-6 py-12 text-center">
                        <x-spinner variant="forest" size="md" />
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Checking sites and dependencies…') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Building the switch preview for :target.', ['target' => $switchTargetLabel]) }}</p>
                        </div>
                    </div>
                @elseif ($switch_plan['blocker'] !== null)
                    <div class="rounded-xl border border-rose-200 bg-rose-50/70 px-4 py-3">
                        <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-900">
                            <x-heroicon-m-no-symbol class="h-4 w-4" />
                            {{ __('Cannot switch') }}
                        </p>
                        <p class="mt-2 text-sm leading-relaxed text-rose-900">{{ $switch_plan['blocker']['label'] }}</p>
                    </div>
                @else
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Always applied') }}</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($switch_plan['auto'] as $row)
                                <li class="flex items-start gap-2 text-sm text-brand-ink">
                                    <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                    <span>{{ $row['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if (! empty($switch_plan['optIn']))
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Opt in') }}</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($switch_plan['optIn'] as $row)
                                    @php
                                        $wireModel = match ($row['key']) {
                                            'tls_to_caddy' => 'switch_tls_to_caddy',
                                            default => null,
                                        };
                                    @endphp
                                    @if ($wireModel)
                                        <li class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                                            <input id="switch-optin-{{ $row['key'] }}" type="checkbox" wire:model="{{ $wireModel }}" class="mt-0.5 h-4 w-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                                            <label for="switch-optin-{{ $row['key'] }}" class="text-sm leading-relaxed text-brand-ink">{{ $row['label'] }}</label>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Estimated timing') }}</p>
                        <ul class="mt-2 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                            @foreach ($switch_plan['downtime'] as $phase)
                                @php
                                    $secs = max(1, (int) round($phase['estimate_ms'] / 1000));
                                    $secLabel = $phase['estimate_ms'] < 1000
                                        ? trans_choice(':n ms|:n ms', $phase['estimate_ms'], ['n' => $phase['estimate_ms']])
                                        : trans_choice(':n second|:n seconds', $secs, ['n' => $secs]);
                                @endphp
                                <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span class="text-brand-ink">{{ $phase['label'] }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="font-mono text-[11px] text-brand-moss">~{{ $secLabel }}</span>
                                        @if ($phase['blocking'])
                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('downtime') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('live') }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if (! empty($switch_plan['manual']))
                        <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-900">
                                <x-heroicon-m-information-circle class="h-4 w-4" />
                                {{ __('Cannot be fixed from here') }}
                            </p>
                            <ul class="mt-2 space-y-1 text-sm text-amber-900">
                                @foreach ($switch_plan['manual'] as $line)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 inline-block h-1 w-1 shrink-0 rounded-full bg-amber-700"></span>
                                        <span>{{ $line }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </div>

            <div class="shrink-0 flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelSwitchWebserver">{{ __('Cancel') }}</x-secondary-button>
                @if ($switch_plan !== null && $switch_plan['blocker'] === null)
                    <x-primary-button type="button" wire:click="confirmSwitchWebserver" wire:loading.attr="disabled" wire:target="confirmSwitchWebserver" class="inline-flex items-center gap-2">
                        <span wire:loading wire:target="confirmSwitchWebserver" class="inline-flex">
                            <x-spinner variant="cream" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="confirmSwitchWebserver">
                            {{ __('Switch to :to', ['to' => $switch_plan['to']]) }}
                        </span>
                        <span wire:loading wire:target="confirmSwitchWebserver">
                            {{ __('Switching…') }}
                        </span>
                    </x-primary-button>
                @endif
            </div>
        </x-modal>
    @endif
