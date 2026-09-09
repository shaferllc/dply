                <div class="{{ $card }}">
                    {{-- Dense head, matching the rest of the workspace. Was a
                         "TEMPLATES" eyebrow + title + a four-line paragraph in a
                         py-5 band; the eyebrow restated the tab. --}}
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-document-duplicate"
                        :title="__('Bundled templates')"
                        :note="__('Each card lists the exact rules it manages. Click to add the rules to this server’s list, or — when they’re all already present — to remove them again. The SSH rule is always kept so you can’t lock yourself out. Changes take effect on the next Apply.')"
                        :count="trans_choice('{1} :count template|[2,*] :count templates', count($bundledTemplates), ['count' => count($bundledTemplates)])"
                        class="border-b border-brand-ink/10"
                    />
                    <div class="space-y-8 px-6 py-4 sm:px-8">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($bundledTemplates as $bKey => $b)
                                @php
                                    $meta = $bundledAppliedMap[$bKey] ?? ['state' => 'none', 'present_count' => 0, 'total' => count($b['rules'] ?? []), 'removable_count' => 0, 'has_ssh' => false, 'chips' => []];
                                    $state = $meta['state'];
                                    $chips = $meta['chips'] ?? [];
                                    $portsSummary = collect($chips)->pluck('label')->implode(', ');
                                    // When fully applied we toggle to REMOVE; otherwise we ADD (missing ones).
                                    $isAll = $state === 'all';
                                    $isPartial = $state === 'partial';
                                    if ($isAll) {
                                        $clickAction = "removeBundledFirewallTemplate('{$bKey}')";
                                    } else {
                                        $clickAction = "applyBundledFirewallTemplate('{$bKey}')";
                                    }
                                @endphp
                                <button
                                    type="button"
                                    wire:click="{{ $clickAction }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $clickAction }}"
                                    @class([
                                        'group flex flex-col items-start gap-1.5 rounded-xl border px-3.5 py-3 text-left transition-colors',
                                        'border-emerald-200 bg-emerald-50/40 hover:border-rose-300 hover:bg-rose-50/60' => $isAll,
                                        'border-amber-200 bg-amber-50/40 hover:border-amber-300 hover:bg-amber-50/70' => $isPartial,
                                        'border-brand-ink/10 bg-white hover:border-brand-forest/30 hover:bg-brand-sand/30' => $state === 'none',
                                    ])
                                >
                                    <div class="flex w-full items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-brand-ink">{{ __($b['label'] ?? $bKey) }}</span>
                                        @if ($isAll)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('Applied') }}
                                            </span>
                                        @elseif ($isPartial)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">
                                                {{ __(':p of :t', ['p' => $meta['present_count'], 't' => $meta['total']]) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ trans_choice('{1} :n rule|[2,*] :n rules', $meta['total'], ['n' => $meta['total']]) }}
                                            </span>
                                        @endif
                                    </div>

                                    @if (! empty($b['description']))
                                        <p class="text-xs leading-relaxed text-brand-moss">{{ __($b['description']) }}</p>
                                    @endif

                                    {{-- Concrete rules this preset manages --}}
                                    @if (! empty($chips))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($chips as $chip)
                                                <span @class([
                                                    'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-2xs ring-1',
                                                    'bg-emerald-50 text-emerald-700 ring-emerald-200' => $chip['present'],
                                                    'bg-white text-brand-moss ring-brand-ink/10' => ! $chip['present'],
                                                ])
                                                    @if ($chip['present']) title="{{ __('Already in panel') }}" @else title="{{ __('Not yet in panel') }}" @endif
                                                >
                                                    {{ $chip['label'] }}
                                                    @if ($chip['is_ssh'])
                                                        <span class="text-brand-sage" title="{{ __('SSH lifeline — never auto-removed') }}">🔒</span>
                                                    @endif
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- State-aware action hint --}}
                                    @if ($isAll)
                                        @if ($meta['removable_count'] > 0)
                                            <p class="text-2xs font-semibold uppercase tracking-wide text-rose-700">
                                                {{ trans_choice('{1} Click to remove :n rule|[2,*] Click to remove :n rules', $meta['removable_count'], ['n' => $meta['removable_count']]) }}
                                                @if ($meta['has_ssh']) · {{ __('SSH kept') }} @endif
                                            </p>
                                        @else
                                            <p class="text-2xs uppercase tracking-wide text-emerald-700">{{ __('All rules present · only SSH (kept) — nothing to remove') }}</p>
                                        @endif
                                    @elseif ($isPartial)
                                        <p class="text-2xs font-semibold uppercase tracking-wide text-amber-700">{{ __('Partially applied · click to add the missing rule(s)') }}</p>
                                    @else
                                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Click to add') }}@if ($portsSummary !== '') · {{ $portsSummary }} @endif</p>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($savedTemplates->isNotEmpty())
                            <div>
                                {{-- In-body sub-heading, at the metrics Settings uses
                                     for the same thing (h3 text-sm + text-xs note)
                                     rather than a section-sized text-base + text-sm. --}}
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Saved templates') }}</h3>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Organization or server-scoped templates.') }}</p>
                                <ul class="mt-3 space-y-2">
                                    @foreach ($savedTemplates as $tpl)
                                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 px-3 py-2 text-sm">
                                            <span>
                                                <span class="font-medium text-brand-ink">{{ $tpl->name }}</span>
                                                @if ($tpl->server_id)
                                                    <span class="ml-2 text-xs text-brand-moss">{{ __('This server') }}</span>
                                                @else
                                                    <span class="ml-2 text-xs text-brand-moss">{{ __('Organization') }}</span>
                                                @endif
                                            </span>
                                            <button
                                                type="button"
                                                wire:click="applySavedFirewallTemplate('{{ $tpl->id }}')"
                                                class="text-xs font-medium text-brand-forest hover:underline"
                                            >
                                                {{ __('Apply') }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="border-t border-brand-ink/10 pt-6">
                            <p class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-brand-mist" />
                                {{ __('To save the rules currently in the panel as a reusable template, use the “More → Save as template” menu on the Rules tab.') }}
                            </p>
                        </div>
                    </div>
                </div>
