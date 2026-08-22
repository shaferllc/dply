{{-- Standalone Schedule page — merged chrome, matching Workers (its twin):
     one outer card, hairline strips, no stacked floating cards. --}}
@php
    $latestSchedule = $latestTick;
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Schedule'),
        'currentIcon' => 'calendar-days',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        {{-- wire:poll refreshes every 15s so ticks recorded by the tick
             command show up as new history rows + a fresh latest-output
             strip. With the scheduler off nothing new arrives, so the cost
             is one round-trip with no UI change. --}}
        <main class="min-w-0 lg:col-span-9" wire:poll.15s>
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-calendar-days"
                    :title="__('Schedule')"
                    :note="__('Scheduled invocations — dply fires your function on a one-minute cadence so its scheduler can run.')"
                />

                @if ($secretMismatchDetected)
                    {{-- The function rejected the latest tick: its baked
                         DPLY_COMMAND_SECRET no longer matches what dply signs
                         with. The deploy preparer upserts the secret every
                         deploy, so one redeploy clears the drift. --}}
                    <div class="border-b border-amber-200/80 bg-amber-50/60 px-3 py-2.5 sm:px-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-1 items-start gap-2.5">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-800" aria-hidden="true" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('Function holds a stale command secret') }}</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Latest tick rejected — redeploy once to bake the current secret into the function.') }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="redeployToRefreshSecret"
                                wire:loading.attr="disabled"
                                wire:target="redeployToRefreshSecret"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-900 px-2.5 py-1.5 text-xs font-semibold text-amber-50 shadow-sm hover:bg-amber-950 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="redeployToRefreshSecret" />
                                <span wire:loading.remove wire:target="redeployToRefreshSecret">{{ __('Redeploy') }}</span>
                                <span wire:loading wire:target="redeployToRefreshSecret">{{ __('Queueing…') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Scheduler toggle --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 sm:px-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Run the scheduler every minute') }}</p>
                        <p class="mt-0.5 text-xs leading-snug text-brand-moss">
                            {{ __('Minute-cadence tick runs the app scheduler when enabled.') }}
                            @if ($lastTickAt)
                                <span class="text-brand-mist">·</span>
                                {{ __('Last tick:') }}
                                <span class="font-mono text-brand-moss">{{ \Illuminate\Support\Carbon::parse($lastTickAt)->diffForHumans() }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
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
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            title="{{ __('Fire one scheduler ping immediately, without waiting for the next minute.') }}"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5" wire:loading.class="animate-pulse" wire:target="tickNow" />
                            <span wire:loading.remove wire:target="tickNow">{{ __('Tick now') }}</span>
                            <span wire:loading wire:target="tickNow">{{ __('Ticking…') }}</span>
                        </button>
                    </div>
                </div>

                @if ($latestSchedule)
                    <div class="border-b border-brand-ink/10">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                            <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Latest output') }}
                            </h3>
                            <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-xs text-brand-moss">
                                <span @class([
                                    'inline-flex items-center rounded-full px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em]',
                                    'bg-emerald-100 text-emerald-900' => ($latestSchedule['status'] ?? '') === 'ok',
                                    'bg-rose-100 text-rose-900' => ($latestSchedule['status'] ?? '') !== 'ok',
                                ])>{{ $latestSchedule['status'] ?? 'unknown' }}</span>
                                @if (! empty($latestSchedule['http_status']))
                                    <span class="font-mono">HTTP {{ $latestSchedule['http_status'] }}</span>
                                @endif
                                <span class="font-mono">{{ (int) ($latestSchedule['duration_ms'] ?? 0) }}ms</span>
                                <span title="{{ $latestSchedule['at'] ?? '' }}">{{ \Illuminate\Support\Carbon::parse($latestSchedule['at'])->diffForHumans() }}</span>
                            </div>
                        </div>
                        <div class="px-3 py-2.5 sm:px-4">
                            @if (! empty($latestSchedule['error']))
                                <div class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-xs text-rose-900">
                                    <p class="font-semibold">{{ __('Error') }}</p>
                                    <p class="mt-0.5 font-mono">{{ $latestSchedule['error'] }}</p>
                                </div>
                            @endif
                            @php($body = trim((string) ($latestSchedule['body_preview'] ?? '')))
                            @if ($body !== '')
                                <pre @class([
                                    'max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-100',
                                    'mt-2' => ! empty($latestSchedule['error']),
                                ])>{{ $body }}</pre>
                            @else
                                <p @class([
                                    'text-xs text-brand-moss',
                                    'mt-2' => ! empty($latestSchedule['error']),
                                ])>{{ __('No response body captured.') }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Firing history --}}
                <div>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-clock class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Firing history') }}
                        </h3>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Every scheduler tick. Newest first. Click a row for full output.') }}">
                            {{ __('Every scheduler tick · click a row for detail') }}
                        </p>
                        <span class="shrink-0 text-xs tabular-nums text-brand-moss">{{ trans_choice('{0} none|{1} :count tick|[2,*] :count ticks', $scheduleHistory->total(), ['count' => $scheduleHistory->total()]) }}</span>
                    </div>

                    @if ($scheduleHistory->isEmpty())
                        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
                            @if ($scheduler_enabled)
                                {{ __('No ticks yet — the first row should land within ~60 seconds.') }}
                            @else
                                {{ __('The scheduler is disabled. Enable it above to start minute-cadence ticks.') }}
                            @endif
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead class="text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-1.5 pr-3 sm:px-4">{{ __('When') }}</th>
                                        <th class="py-1.5 pr-3">{{ __('Status') }}</th>
                                        <th class="py-1.5 pr-3">{{ __('HTTP') }}</th>
                                        <th class="py-1.5 pr-3">{{ __('Duration') }}</th>
                                        <th class="py-1.5 pr-3 sm:pr-4">{{ __('Detail') }}</th>
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
                                            <td class="px-3 py-1.5 pr-3 text-xs text-brand-ink sm:px-4">
                                                {{ \Illuminate\Support\Carbon::parse($entry['at'])->diffForHumans() }}
                                            </td>
                                            <td class="py-1.5 pr-3">
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em]',
                                                    'bg-emerald-100 text-emerald-900' => ($entry['status'] ?? '') === 'ok',
                                                    'bg-rose-100 text-rose-900' => ($entry['status'] ?? '') !== 'ok',
                                                ])>{{ $entry['status'] ?? 'unknown' }}</span>
                                            </td>
                                            <td class="py-1.5 pr-3 font-mono text-xs text-brand-moss">
                                                {{ $entry['http_status'] ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 font-mono text-xs text-brand-moss">
                                                {{ (int) ($entry['duration_ms'] ?? 0) }}ms
                                            </td>
                                            <td class="py-1.5 pr-3 break-all font-mono text-xs text-brand-moss sm:pr-4">
                                                @if (! empty($entry['error']))
                                                    <span class="text-rose-700">{{ \Illuminate\Support\Str::limit($entry['error'], 100) }}</span>
                                                @else
                                                    {{ \Illuminate\Support\Str::limit(trim((string) ($entry['body_preview'] ?? '')), 100) ?: '—' }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <x-table-pager :paginator="$scheduleHistory" page-name="tickPage" :noun="__('ticks')" class="border-t border-brand-ink/10 px-3 py-2 sm:px-4" />
                    @endif
                </div>

                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
                    <x-cli-snippet :commands="[
                        ['label' => __('Scheduler state + recent ticks'), 'command' => 'dply serverless schedule '.$site->slug],
                        ['label' => __('Turn the scheduler on / off'), 'command' => 'dply serverless schedule '.$site->slug.' --enable'],
                        ['label' => __('Fire one scheduler tick now'), 'command' => 'dply serverless schedule '.$site->slug.' --tick'],
                        ['label' => __('Failed ticks only, for scripts'), 'command' => 'dply serverless schedule '.$site->slug.' --failed --json'],
                        ['label' => __('Cron triggers on the functions host'), 'command' => 'dply serverless platform '.$site->slug.' --schedules'],
                    ]" />
                </div>
            </section>
        </main>
    </div>

    @include('livewire.sites.partials.tick-detail-modal')
</div>
