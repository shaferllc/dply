<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Schedule'),
        'currentIcon' => 'calendar-days',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        {{-- wire:poll refreshes every 15s so the tick command's writes to
             site meta show up here as new history rows + a fresh "last
             output" panel. When the scheduler is disabled there's nothing
             new arriving, so the polling cost is just one round-trip with
             no UI change. --}}
        <main class="min-w-0 space-y-6 lg:col-span-9" wire:poll.15s>
            <x-hero-card
                :eyebrow="__('Site')"
                :title="__('Schedule')"
                :description="__('Engine-level scheduled invocations — cron-like rules that fire your function or run a command on a timer.')"
                icon="clock"
            />

            @if ($secretMismatchDetected)
                {{-- Function rejected the latest tick because its baked
                     DPLY_COMMAND_SECRET doesn't match the site's current
                     webhook_secret. The deploy preparer now upserts the
                     secret on every deploy, so a single redeploy resolves
                     the drift. --}}
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Warning') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Function holds a stale command secret') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('The latest tick was rejected by the function with "invalid command secret" — its baked DPLY_COMMAND_SECRET doesn\'t match what dply is signing requests with. Redeploy once to bake the current secret into the function; ticks succeed from there on.') }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="redeployToRefreshSecret"
                                wire:loading.attr="disabled"
                                wire:target="redeployToRefreshSecret"
                                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-amber-900 px-3 py-2 text-xs font-semibold text-amber-50 shadow-sm hover:bg-amber-950 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="redeployToRefreshSecret" />
                                <span wire:loading.remove wire:target="redeployToRefreshSecret">{{ __('Redeploy to refresh secret') }}</span>
                                <span wire:loading wire:target="redeployToRefreshSecret">{{ __('Queueing…') }}</span>
                            </button>
                        </div>
                    </div>
                </section>
            @endif

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scheduler') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Run the scheduler every minute') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('When enabled, dply invokes the function in scheduler mode every minute. Use this for Laravel `schedule:run`, periodic queue draining, or any one-minute-cadence job. Future versions will let you define multiple named cron rules here.') }}
                        </p>
                        @if ($lastTickAt)
                            <p class="mt-2 text-xs text-brand-moss">
                                {{ __('Last tick:') }} <span class="font-mono">{{ \Illuminate\Support\Carbon::parse($lastTickAt)->diffForHumans() }}</span>
                            </p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-3">
                        <x-toggle-switch
                            wire:model.live="scheduler_enabled"
                            :enabled="$scheduler_enabled"
                            :on-label="__('Enabled')"
                            :off-label="__('Disabled')"
                        />
                        <button
                            type="button"
                            wire:click="tickNow"
                            wire:loading.attr="disabled"
                            wire:target="tickNow"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            title="{{ __('Fire one scheduler ping immediately, without waiting for the next cron interval.') }}"
                        >
                            <x-heroicon-o-bolt class="h-4 w-4" wire:loading.class="animate-pulse" wire:target="tickNow" />
                            <span wire:loading.remove wire:target="tickNow">{{ __('Tick now') }}</span>
                            <span wire:loading wire:target="tickNow">{{ __('Ticking…') }}</span>
                        </button>
                    </div>
                </div>
            </section>

            @php
                $latestSchedule = $scheduleHistory->first();
            @endphp
            @if ($latestSchedule)
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Output') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Latest output') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Most recent scheduler invocation — the function\'s response body, captured by the tick command. Refreshes every 15 seconds.') }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 font-semibold uppercase tracking-[0.12em]',
                                'bg-emerald-100 text-emerald-900' => ($latestSchedule['status'] ?? '') === 'ok',
                                'bg-rose-100 text-rose-900' => ($latestSchedule['status'] ?? '') !== 'ok',
                            ])>{{ $latestSchedule['status'] ?? 'unknown' }}</span>
                            @if (! empty($latestSchedule['http_status']))
                                <span class="font-mono text-brand-moss">HTTP {{ $latestSchedule['http_status'] }}</span>
                            @endif
                            <span class="font-mono text-brand-moss">{{ (int) ($latestSchedule['duration_ms'] ?? 0) }}ms</span>
                            <span class="text-brand-moss" title="{{ $latestSchedule['at'] ?? '' }}">{{ \Illuminate\Support\Carbon::parse($latestSchedule['at'])->diffForHumans() }}</span>
                        </div>
                    </div>
                    <div class="px-6 py-6 sm:px-7">
                        @if (! empty($latestSchedule['error']))
                            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                                <p class="font-semibold">{{ __('Error') }}</p>
                                <p class="mt-1 font-mono">{{ $latestSchedule['error'] }}</p>
                            </div>
                        @endif
                        @php($body = trim((string) ($latestSchedule['body_preview'] ?? '')))
                        @if ($body !== '')
                            <pre class="@if (! empty($latestSchedule['error'])) mt-4 @endif max-h-[28rem] overflow-auto rounded-lg bg-slate-900 p-4 font-mono text-[11px] leading-relaxed text-slate-100">{{ $body }}</pre>
                        @else
                            <p class="@if (! empty($latestSchedule['error'])) mt-4 @endif text-xs text-brand-moss">{{ __('No response body captured.') }}</p>
                        @endif
                    </div>
                </section>
            @endif

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Firing history') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Last 50 scheduler ticks. Newest first. Click a row to see its full output.') }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs text-brand-moss">{{ trans_choice('{0} no ticks yet|{1} :count tick recorded|[2,*] :count ticks recorded', $scheduleHistory->count(), ['count' => $scheduleHistory->count()]) }}</span>
                </div>

                <div class="px-6 py-6 sm:px-7">
                @if ($scheduleHistory->isEmpty())
                    <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                        @if ($scheduler_enabled)
                            {{ __('No ticks recorded yet. dply runs the tick command every minute — the first row should land within ~60 seconds.') }}
                        @else
                            {{ __('The scheduler is disabled. Enable it above and dply starts ticking every minute; rows appear here as they fire.') }}
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                <tr>
                                    <th class="py-2 pr-3">{{ __('When') }}</th>
                                    <th class="py-2 pr-3">{{ __('Status') }}</th>
                                    <th class="py-2 pr-3">{{ __('HTTP') }}</th>
                                    <th class="py-2 pr-3">{{ __('Duration') }}</th>
                                    <th class="py-2">{{ __('Detail') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($scheduleHistory as $entry)
                                    <tr
                                        wire:key="tick-{{ $entry['at'] ?? $loop->index }}"
                                        wire:click="showTick('{{ $entry['at'] ?? '' }}')"
                                        class="cursor-pointer transition-colors hover:bg-brand-sand/40"
                                        title="{{ __('Click to see full output') }}"
                                    >
                                        <td class="py-2 pr-3 text-xs text-brand-ink">
                                            {{ \Illuminate\Support\Carbon::parse($entry['at'])->diffForHumans() }}
                                        </td>
                                        <td class="py-2 pr-3">
                                            <span @class([
                                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em]',
                                                'bg-emerald-100 text-emerald-900' => ($entry['status'] ?? '') === 'ok',
                                                'bg-rose-100 text-rose-900' => ($entry['status'] ?? '') !== 'ok',
                                            ])>{{ $entry['status'] ?? 'unknown' }}</span>
                                        </td>
                                        <td class="py-2 pr-3 font-mono text-xs text-brand-moss">
                                            {{ $entry['http_status'] ?? '—' }}
                                        </td>
                                        <td class="py-2 pr-3 font-mono text-xs text-brand-moss">
                                            {{ (int) ($entry['duration_ms'] ?? 0) }}ms
                                        </td>
                                        <td class="py-2 break-all font-mono text-[11px] text-brand-moss">
                                            @if (! empty($entry['error']))
                                                <span class="text-rose-700">{{ \Illuminate\Support\Str::limit($entry['error'], 120) }}</span>
                                            @else
                                                {{ \Illuminate\Support\Str::limit(trim((string) ($entry['body_preview'] ?? '')), 120) ?: '—' }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </div>
            </section>

            <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-6 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('Coming next') }}</p>
                <p class="mt-1">{{ __('Multiple cron rules per app, with cron expression + target + timezone + retry policy + last/next run timestamps. The single-toggle scheduler above is the v1 stand-in until that ships.') }}</p>
            </section>

            <x-cli-snippet class="mt-6" :command="'dply sites:schedules '.$site->slug" />
        </main>
    </div>

    @include('livewire.sites.partials.tick-detail-modal')
</div>
