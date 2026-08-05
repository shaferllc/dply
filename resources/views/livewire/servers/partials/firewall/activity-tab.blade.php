                <div class="{{ $card }}">
                    {{-- Dense head, matching the rest of the workspace. Was an icon
                         badge + "ACTIVITY" eyebrow + title + prose + a meta row;
                         the eyebrow restated the tab and the count/recency now
                         ride the count pill. --}}
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-clock"
                        :title="__('Audit log')"
                        :note="__('Apply runs, rule edits, template applications, and imports — chronologically. Apply rows are expandable for the full UFW transcript.')"
                        :count="trans_choice('{0} no events|{1} :count event|[2,*] :count events', $activityCount, ['count' => $activityCount])
                            . ($latestActivity ? ' · '.__('latest :time', ['time' => $latestActivity->diffForHumans()]) : '')"
                        class="border-b border-brand-ink/10"
                    />

                    @if ($activityCount > 0)
                        <div class="space-y-2 px-6 py-5 sm:px-8">
                            @foreach ($activityItems as $item)
                                @if ($item['kind'] === 'apply')
                                    @php
                                        $log = $item['log'];
                                        $isSuccess = (bool) $log->success;
                                        $logLines = $linesOf($log->message);
                                    @endphp
                                    <details class="group overflow-hidden rounded-xl border border-brand-ink/10 bg-white" wire:key="activity-{{ $item['key'] }}">
                                        <summary class="flex cursor-pointer list-none items-start gap-3 px-4 py-3 sm:px-5">
                                            <span @class([
                                                'mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1',
                                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $isSuccess,
                                                'bg-rose-50 text-rose-700 ring-rose-200' => ! $isSuccess,
                                            ])>
                                                @if ($isSuccess)
                                                    <x-heroicon-m-check class="h-4 w-4" />
                                                @else
                                                    <x-heroicon-m-exclamation-triangle class="h-4 w-4" />
                                                @endif
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span @class([
                                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1',
                                                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $isSuccess,
                                                        'bg-rose-50 text-rose-800 ring-rose-200' => ! $isSuccess,
                                                    ])>
                                                        {{ $isSuccess ? __('Applied') : __('Failed') }}
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss" title="{{ $log->rules_hash ?? '—' }}">
                                                        <x-heroicon-m-hashtag class="h-3 w-3" />
                                                        {{ $log->rules_hash ? substr($log->rules_hash, 0, 12) : '—' }}
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 text-[11px] text-brand-mist">
                                                        {{ trans_choice('{0} 0 rules|{1} :count rule|[2,*] :count rules', (int) $log->rule_count, ['count' => (int) $log->rule_count]) }}
                                                    </span>
                                                    @if ($log->source)
                                                        <span class="inline-flex items-center rounded-md border border-brand-ink/10 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-brand-moss">{{ $log->source }}</span>
                                                    @endif
                                                    <span class="ml-auto text-[11px] text-brand-mist" title="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->diffForHumans() }}</span>
                                                </div>
                                                @if ($log->user)
                                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('by :name', ['name' => $log->user->name ?? $log->user->email]) }}</p>
                                                @endif
                                                @if (count($logLines) > 0)
                                                    <p class="mt-1 truncate font-mono text-[11px] text-brand-moss">{{ $logLines[count($logLines) - 1] }}</p>
                                                @endif
                                            </div>
                                            <span class="ml-2 mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-brand-mist transition-transform group-open:rotate-180">
                                                <x-heroicon-o-chevron-down class="h-4 w-4" />
                                            </span>
                                        </summary>
                                        @if (count($logLines) > 0)
                                            <div class="border-t border-brand-ink/8 bg-brand-sand/15 px-4 py-3 sm:px-5">
                                                <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100">@foreach ($logLines as $line){{ $line }}
@endforeach</pre>
                                            </div>
                                        @endif
                                    </details>
                                @else
                                    <div wire:key="activity-{{ $item['key'] }}">
                                        @include('livewire.servers.partials.activity-audit-row', ['event' => $item['event'], 'server' => $server])
                                    </div>
                                @endif
                            @endforeach

                            @if (! $activity_exhausted)
                                <div class="flex justify-center pt-2">
                                    <button
                                        type="button"
                                        wire:click="loadMoreFirewallActivity"
                                        wire:loading.attr="disabled"
                                        wire:target="loadMoreFirewallActivity"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <x-heroicon-o-arrow-down class="h-4 w-4" wire:loading.remove wire:target="loadMoreFirewallActivity" />
                                        <span wire:loading wire:target="loadMoreFirewallActivity" class="inline-flex h-4 w-4 items-center justify-center">
                                            <x-spinner variant="forest" size="sm" />
                                        </span>
                                        <span wire:loading.remove wire:target="loadMoreFirewallActivity">{{ __('Load older activity') }}</span>
                                        <span wire:loading wire:target="loadMoreFirewallActivity">{{ __('Loading…') }}</span>
                                    </button>
                                </div>
                            @elseif ($activity_visible >= \App\Livewire\Servers\WorkspaceFirewall::ACTIVITY_MAX_VISIBLE)
                                <p class="pt-2 text-center text-[11px] italic text-brand-mist">
                                    {{ __('Showing the most recent :n events. Older history lives in audit logs.', ['n' => \App\Livewire\Servers\WorkspaceFirewall::ACTIVITY_MAX_VISIBLE]) }}
                                </p>
                            @endif
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-2 px-6 py-10 text-center sm:px-8">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-clock class="h-5 w-5" />
                            </span>
                            <p class="text-sm font-medium text-brand-ink">{{ __('No firewall activity yet.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Adding, editing, importing, or applying rules will all show up here.') }}</p>
                        </div>
                    @endif
                </div>
