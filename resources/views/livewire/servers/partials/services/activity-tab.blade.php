<div class="{{ $card }} p-6 sm:p-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                        <x-heroicon-o-clock class="h-5 w-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Service activity') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Started, stopped, restarted, and state-change events Dply observed between inventory snapshots.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
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

                @if ($activityCount > 0)
                    <ul class="mt-6 space-y-2">
                        @foreach ($systemdServiceActivity as $ev)
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
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-1 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 text-sm">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full ring-1 {{ $iconCls }}">
                                    <x-dynamic-component :component="$iconComponent" class="h-3.5 w-3.5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-semibold text-brand-ink">{{ $kindLabel }}</span>
                                        <span class="font-mono text-xs text-brand-moss">{{ $ev['label'] ?? $ev['unit'] ?? '' }}</span>
                                        @if ($atRel)
                                            <span class="ml-auto text-[11px] text-brand-mist" title="{{ $atEv }}">{{ $atRel }}</span>
                                        @endif
                                    </div>
                                    @if (! empty($ev['detail']))
                                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ $ev['detail'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No service activity yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Start, stop, or restart a unit and the events will show up here as Dply detects them.') }}</p>
                    </div>
                @endif
            </div>

@livewire(\App\Livewire\Servers\RecentActionsLog::class, ['server' => $server], key('recent-actions-log-'.$server->id))
