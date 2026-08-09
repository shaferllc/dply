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
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-clock"
                    :title="__('Schedule')"
                    :note="__('Engine-level scheduled invocations — cron-like rules that fire your function or run a command on a timer.')"
                />
            </section>

            @if ($secretMismatchDetected)
                {{-- Function rejected the latest tick because its baked
                     DPLY_COMMAND_SECRET doesn't match the site's current
                     webhook_secret. The deploy preparer now upserts the
                     secret on every deploy, so a single redeploy resolves
                     the drift. --}}
                <section class="dply-card overflow-hidden border-amber-200">
                    <x-workspace-panel-head
                        dense
                        tone="amber"
                        icon="heroicon-o-exclamation-triangle"
                        :title="__('Function holds a stale command secret')"
                        :note="__('The latest tick was rejected with “invalid command secret” — the function\'s baked DPLY_COMMAND_SECRET doesn\'t match what dply signs with. One redeploy bakes in the current secret.')"
                    >
                        <x-slot:actions>
                            <button
                                type="button"
                                wire:click="redeployToRefreshSecret"
                                wire:loading.attr="disabled"
                                wire:target="redeployToRefreshSecret"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-900 px-2.5 py-1 text-2xs font-semibold text-amber-50 shadow-sm hover:bg-amber-950 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="redeployToRefreshSecret" />
                                <span wire:loading.remove wire:target="redeployToRefreshSecret">{{ __('Redeploy to refresh secret') }}</span>
                                <span wire:loading wire:target="redeployToRefreshSecret">{{ __('Queueing…') }}</span>
                            </button>
                        </x-slot:actions>
                    </x-workspace-panel-head>
                </section>
            @endif

            <section class="dply-card overflow-hidden">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Run the scheduler every minute')"
                    :count="$lastTickAt ? __('last tick :when', ['when' => \Illuminate\Support\Carbon::parse($lastTickAt)->diffForHumans()]) : null"
                    :note="__('When enabled, dply invokes the function in scheduler mode every minute — Laravel `schedule:run`, periodic queue draining, or any one-minute-cadence job.')"
                >
                    <x-slot:actions>
                        <button
                            type="button"
                            wire:click="tickNow"
                            wire:loading.attr="disabled"
                            wire:target="tickNow"
                            class="dply-btn dply-btn-xs dply-btn-outline"
                            title="{{ __('Fire one scheduler ping immediately, without waiting for the next cron interval.') }}"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-pulse" wire:target="tickNow" />
                            <span wire:loading.remove wire:target="tickNow">{{ __('Tick now') }}</span>
                            <span wire:loading wire:target="tickNow">{{ __('Ticking…') }}</span>
                        </button>
                        <x-toggle-switch
                            wire:model.live="scheduler_enabled"
                            :enabled="$scheduler_enabled"
                            :on-label="__('Enabled')"
                            :off-label="__('Disabled')"
                        />
                    </x-slot:actions>
                </x-workspace-panel-head>
            </section>

            @php
                $latestSchedule = $scheduleHistory->first();
            @endphp
            @if ($latestSchedule)
                <section class="dply-card overflow-hidden">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-document-text"
                        :title="__('Latest output')"
                        :note="__('Most recent scheduler invocation, captured by the tick command. Refreshes every 15 seconds.')"
                        :tone="($latestSchedule['status'] ?? '') === 'ok' ? null : 'danger'"
                    >
                        <x-slot:actions>
                            <span @class([
                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.12em]',
                                'bg-emerald-100 text-emerald-900' => ($latestSchedule['status'] ?? '') === 'ok',
                                'bg-rose-100 text-rose-900' => ($latestSchedule['status'] ?? '') !== 'ok',
                            ])>{{ $latestSchedule['status'] ?? 'unknown' }}</span>
                            @if (! empty($latestSchedule['http_status']))
                                <span class="font-mono text-2xs text-brand-moss">HTTP {{ $latestSchedule['http_status'] }}</span>
                            @endif
                            <span class="font-mono text-2xs text-brand-moss">{{ (int) ($latestSchedule['duration_ms'] ?? 0) }}ms</span>
                            <span class="text-2xs text-brand-moss" title="{{ $latestSchedule['at'] ?? '' }}">{{ \Illuminate\Support\Carbon::parse($latestSchedule['at'])->diffForHumans() }}</span>
                        </x-slot:actions>
                    </x-workspace-panel-head>

                    <div class="px-3 py-3 sm:px-4">
                        @if (! empty($latestSchedule['error']))
                            <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                                <p class="font-semibold">{{ __('Error') }}</p>
                                <p class="mt-0.5 font-mono">{{ $latestSchedule['error'] }}</p>
                            </div>
                        @endif
                        @php($body = trim((string) ($latestSchedule['body_preview'] ?? '')))
                        @if ($body !== '')
                            <pre class="@if (! empty($latestSchedule['error'])) mt-2 @endif max-h-96 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-100">{{ $body }}</pre>
                        @else
                            <p class="@if (! empty($latestSchedule['error'])) mt-2 @endif text-xs text-brand-moss">{{ __('No response body captured.') }}</p>
                        @endif
                    </div>
                </section>
            @endif

            <section class="dply-card overflow-hidden">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-clock"
                    :title="__('Firing history')"
                    :count="trans_choice('{0} none|{1} :count|[2,*] :count', $scheduleHistory->count(), ['count' => $scheduleHistory->count()])"
                    :note="__('Last 50 scheduler ticks. Newest first. Click a row to see its full output.')"
                />

                <div class="px-3 py-3 sm:px-4">
                @if ($scheduleHistory->isEmpty())
                    <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 px-4 py-6 text-center text-xs text-brand-moss">
                        @if ($scheduler_enabled)
                            {{ __('No ticks recorded yet. dply runs the tick command every minute — the first row should land within ~60 seconds.') }}
                        @else
                            {{ __('The scheduler is disabled. Enable it above and dply starts ticking every minute; rows appear here as they fire.') }}
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
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
                                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em]',
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
                                        <td class="py-2 break-all font-mono text-xs text-brand-moss">
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

            <x-cli-snippet :command="'dply sites:schedules '.$site->slug" />
        </main>
    </div>

    @include('livewire.sites.partials.tick-detail-modal')
</div>
