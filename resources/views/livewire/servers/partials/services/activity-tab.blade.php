<div class="{{ $card }} px-5 py-5 sm:px-6">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Activity') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Service activity') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Started, stopped, restarted, and state-change events Dply observed between inventory snapshots.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $activityCount, ['count' => $activityCount]) }}
                            </span>
                            @if ($latestActivityRel)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('latest :time', ['time' => $latestActivityRel]) }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($activityCount > 0 && $activityEvents)
                    <ul class="mt-5 divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                        @foreach ($activityEvents as $ev)
                            @php
                                $kind = (string) ($ev['kind'] ?? '');
                                $kindLabel = match ($kind) {
                                    'started' => __('Started'),
                                    'stopped' => __('Stopped'),
                                    'restarted' => __('Restarted'),
                                    'state_changed' => __('State change'),
                                    default => $kind,
                                };
                                $atEv = $ev['at'] ?? '';
                                $atRel = null;
                                if ($atEv !== '') {
                                    try {
                                        $atRel = \Carbon\Carbon::parse($atEv)->timezone(config('app.timezone'))->diffForHumans();
                                    } catch (\Throwable) {
                                        $atRel = null;
                                    }
                                }
                                $iconCls = match ($kind) {
                                    'stopped' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    'started' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    'restarted' => 'bg-amber-50 text-amber-800 ring-amber-200',
                                    default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
                                };
                                $iconComponent = match ($kind) {
                                    'stopped' => 'heroicon-o-stop-circle',
                                    'started' => 'heroicon-o-play-circle',
                                    'restarted' => 'heroicon-o-arrow-path',
                                    default => 'heroicon-o-bolt',
                                };
                            @endphp
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 bg-white px-3 py-2.5 text-sm">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1 {{ $iconCls }}">
                                    <x-dynamic-component :component="$iconComponent" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-semibold text-brand-ink">{{ $kindLabel }}</span>
                                        <span class="font-mono text-xs text-brand-moss">{{ $ev['label'] ?? $ev['unit'] ?? '' }}</span>
                                        @if ($atRel)
                                            <span class="ml-auto text-xs text-brand-mist" title="{{ $atEv }}">{{ $atRel }}</span>
                                        @endif
                                    </div>
                                    @if (! empty($ev['detail']))
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $ev['detail'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($activityEvents->hasPages())
                        <div class="mt-4">
                            {{ $activityEvents->links() }}
                        </div>
                    @endif
                @else
                    <p class="mt-5 text-center text-sm text-brand-moss">{{ __('No service activity yet. Start, stop, or restart a unit and events will show up here.') }}</p>
                @endif
            </div>

@livewire(\App\Livewire\Servers\RecentActionsLog::class, ['server' => $server, 'nested' => true], key('recent-actions-log-'.$server->id))
