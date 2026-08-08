            <div class="{{ $card }}">
                {{-- Dense head. The event count moves into the count pill and the
                     "latest" timestamp joins the note, replacing the separate
                     stat row the tall head carried underneath. --}}
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Audit log')"
                    :count="trans_choice('{0} no events|{1} :count event|[2,*] :count events', $activityCount, ['count' => $activityCount])"
                    :note="$latestActivity
                        ? __('Key edits, syncs, deployments, and bulk imports — chronologically. Latest :time.', ['time' => $latestActivity->diffForHumans()])
                        : __('Key edits, syncs, deployments, and bulk imports — chronologically.')"
                    class="border-b border-brand-ink/10"
                />

                @if ($auditEvents->isNotEmpty())
                    {{-- Page-change skeleton. Same shape as the tab skeleton's
                         activity rows so the list doesn't jump, and sized to the
                         page being fetched rather than a fixed count. --}}
                    <div wire:loading.block wire:target="setActivityPage" aria-busy="true" aria-live="polite">
                        <span class="sr-only">{{ __('Loading events…') }}</span>
                        <div class="space-y-1.5 px-4 py-3.5 sm:px-5" aria-hidden="true">
                            @foreach (range(1, max(1, $auditEvents->count())) as $skeletonRow)
                                <div class="flex items-center gap-2.5 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                                    <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                                    <div class="min-w-0 flex-1 space-y-1.5">
                                        <div class="h-2.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                                        <div class="h-2 w-2/3 animate-pulse rounded bg-brand-ink/10"></div>
                                    </div>
                                    <span class="h-2 w-16 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-1.5 px-4 py-3.5 sm:px-5" wire:loading.remove wire:target="setActivityPage">
                        @foreach ($auditEvents as $ev)
                            <div wire:key="ssh-activity-{{ $ev->id }}">
                                @include('livewire.servers.partials.activity-audit-row', ['event' => $ev, 'server' => $server])
                            </div>
                        @endforeach
                    </div>

                    @if (($activityPagination['total_pages'] ?? 1) > 1)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-4 py-2.5 text-[11px] text-brand-moss sm:px-5">
                            <span>
                                {{ __('Showing :from–:to of :total', [
                                    'from' => $activityPagination['from'],
                                    'to' => $activityPagination['to'],
                                    'total' => $activityPagination['total'],
                                ]) }}
                            </span>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    wire:click="setActivityPage({{ max(1, $activityPagination['page'] - 1) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="setActivityPage"
                                    @disabled($activityPagination['page'] <= 1)
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <x-heroicon-o-chevron-left class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Previous') }}
                                </button>
                                <span class="tabular-nums">
                                    {{ __('Page :page of :total', [
                                        'page' => $activityPagination['page'],
                                        'total' => $activityPagination['total_pages'],
                                    ]) }}
                                </span>
                                <button
                                    type="button"
                                    wire:click="setActivityPage({{ min($activityPagination['total_pages'], $activityPagination['page'] + 1) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="setActivityPage"
                                    @disabled($activityPagination['page'] >= $activityPagination['total_pages'])
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {{ __('Next') }}
                                    <x-heroicon-o-chevron-right class="h-3 w-3" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="flex flex-col items-center gap-1.5 px-4 py-8 text-center sm:px-5">
                        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-4 w-4" />
                        </span>
                        <p class="text-xs font-semibold text-brand-ink">{{ __('No SSH key activity yet.') }}</p>
                        <p class="text-[11px] text-brand-moss">{{ __('Adding, editing, syncing, or deploying keys will all show up here.') }}</p>
                    </div>
                @endif
            </div>
