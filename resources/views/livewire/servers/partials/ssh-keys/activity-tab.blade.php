            <div class="{{ $card }}">
                <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Activity') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Key edits, syncs, deployments, and bulk imports — chronologically.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $activityCount, ['count' => $activityCount]) }}
                            </span>
                            @if ($latestActivity)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('latest :time', ['time' => $latestActivity->diffForHumans()]) }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($auditEvents->isNotEmpty())
                    <div class="space-y-2 px-6 py-5 sm:px-8">
                        @foreach ($auditEvents as $ev)
                            <div wire:key="ssh-activity-{{ $ev->id }}">
                                @include('livewire.servers.partials.activity-audit-row', ['event' => $ev, 'server' => $server])
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center gap-2 px-6 py-10 text-center sm:px-8">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No SSH key activity yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Adding, editing, syncing, or deploying keys will all show up here.') }}</p>
                    </div>
                @endif
            </div>
