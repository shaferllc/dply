{{-- Standalone Workers page — merged chrome (no floating hero / stacked cards). --}}
@php
    $latestQueue = $latestTick;
    $workerCommandPlaceholder = $site->isLaravelFrameworkDetected()
        ? 'php artisan queue:work'
        : ($site->isRailsFrameworkDetected() ? 'bundle exec sidekiq' : 'node worker.js');
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Workers'),
        'currentIcon' => 'bolt',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 lg:col-span-9" wire:poll.15s>
            <section class="dply-card min-w-0 overflow-hidden p-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-cpu-chip"
                    :title="__('Workers')"
                    :note="__('Long-running engine processes — queue consumers and background workers for this app.')"
                />

                @if ($secretMismatchDetected)
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

                @if (($dns['status'] ?? null) === 'failed')
                    <div class="border-b border-rose-200/80 bg-rose-50/60 px-3 py-2.5 sm:px-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-1 items-start gap-2.5">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-700" aria-hidden="true" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('DNS provisioning failed') }}</p>
                                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Check the zone/token in DigitalOcean, then retry.') }}</p>
                                    <p class="mt-1 break-all font-mono text-xs text-rose-700">{{ $dns['error'] ?? __('No error detail recorded.') }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="provisionDnsNow"
                                wire:loading.attr="disabled"
                                wire:target="provisionDnsNow"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-rose-700 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-rose-800 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="provisionDnsNow" aria-hidden="true" />
                                <span wire:loading.remove wire:target="provisionDnsNow">{{ __('Retry DNS') }}</span>
                                <span wire:loading wire:target="provisionDnsNow">{{ __('Retrying…') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Queue engine toggle --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 sm:px-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Process queue jobs') }}</p>
                        <p class="mt-0.5 text-xs leading-snug text-brand-moss">
                            {{ __('Minute-cadence tick drains the queue when enabled.') }}
                            @if ($lastTickAt)
                                <span class="text-brand-mist">·</span>
                                {{ __('Last tick:') }}
                                <span class="font-mono text-brand-moss">{{ \Illuminate\Support\Carbon::parse($lastTickAt)->diffForHumans() }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <x-toggle-switch
                            wire:model.live="queue_worker_enabled"
                            :enabled="$queue_worker_enabled"
                            :on-label="__('Enabled')"
                            :off-label="__('Disabled')"
                        />
                        <button
                            type="button"
                            wire:click="tickNow"
                            wire:loading.attr="disabled"
                            wire:target="tickNow"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            title="{{ __('Fire one queue ping immediately, without waiting for the next cron interval.') }}"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5" wire:loading.class="animate-pulse" wire:target="tickNow" />
                            <span wire:loading.remove wire:target="tickNow">{{ __('Tick now') }}</span>
                            <span wire:loading wire:target="tickNow">{{ __('Ticking…') }}</span>
                        </button>
                    </div>
                </div>

                @if ($latestQueue)
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
                                    'bg-emerald-100 text-emerald-900' => ($latestQueue['status'] ?? '') === 'ok',
                                    'bg-rose-100 text-rose-900' => ($latestQueue['status'] ?? '') !== 'ok',
                                ])>{{ $latestQueue['status'] ?? 'unknown' }}</span>
                                @if (! empty($latestQueue['http_status']))
                                    <span class="font-mono">HTTP {{ $latestQueue['http_status'] }}</span>
                                @endif
                                <span class="font-mono">{{ (int) ($latestQueue['duration_ms'] ?? 0) }}ms</span>
                                <span title="{{ $latestQueue['at'] ?? '' }}">{{ \Illuminate\Support\Carbon::parse($latestQueue['at'])->diffForHumans() }}</span>
                            </div>
                        </div>
                        <div class="px-3 py-2.5 sm:px-4">
                            @if (! empty($latestQueue['error']))
                                <div class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-xs text-rose-900">
                                    <p class="font-semibold">{{ __('Error') }}</p>
                                    <p class="mt-0.5 font-mono">{{ $latestQueue['error'] }}</p>
                                </div>
                            @endif
                            @php($body = trim((string) ($latestQueue['body_preview'] ?? '')))
                            @if ($body !== '')
                                <pre @class([
                                    'max-h-64 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-100',
                                    'mt-2' => ! empty($latestQueue['error']),
                                ])>{{ $body }}</pre>
                            @else
                                <p @class([
                                    'text-xs text-brand-moss',
                                    'mt-2' => ! empty($latestQueue['error']),
                                ])>{{ __('No response body captured.') }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Firing history --}}
                <div class="border-b border-brand-ink/10">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-clock class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Firing history') }}
                        </h3>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Every queue tick. Newest first. Click a row for full output.') }}">
                            {{ __('Every queue tick · click a row for detail') }}
                        </p>
                        <span class="shrink-0 text-xs tabular-nums text-brand-moss">{{ trans_choice('{0} none|{1} :count tick|[2,*] :count ticks', $queueHistory->total(), ['count' => $queueHistory->total()]) }}</span>
                    </div>

                    @if ($queueHistory->isEmpty())
                        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
                            @if ($queue_worker_enabled)
                                {{ __('No ticks yet — the first row should land within ~60 seconds.') }}
                            @else
                                {{ __('Workers are disabled. Enable above to start minute-cadence ticks.') }}
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
                                    @foreach ($queueHistory as $entry)
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

                        <x-table-pager :paginator="$queueHistory" page-name="tickPage" :noun="__('ticks')" class="border-t border-brand-ink/10 px-3 py-2 sm:px-4" />
                    @endif
                </div>

                {{-- Named workers --}}
                <div>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-heroicon-o-command-line class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Named workers') }}
                        </h3>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Command, replicas, and restart policy. v1 shares the engine tick above.') }}">
                            {{ __('Command · replicas · restart policy') }}
                        </p>
                        <button
                            type="button"
                            wire:click="newWorker"
                            class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add worker') }}
                        </button>
                    </div>

                    @if (empty($workerRows))
                        <div class="px-3 py-4 text-center text-xs text-brand-moss sm:px-4">
                            {{ __('No workers defined yet. Add one for command, replica count, and restart policy.') }}
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                                <thead class="text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-1.5 sm:px-4">{{ __('Worker') }}</th>
                                        <th class="px-2 py-1.5">{{ __('Command') }}</th>
                                        <th class="px-2 py-1.5">{{ __('Replicas') }}</th>
                                        <th class="px-2 py-1.5">{{ __('Restart') }}</th>
                                        <th class="px-2 py-1.5">{{ __('Status') }}</th>
                                        <th class="px-3 py-1.5 text-right sm:px-4">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10">
                                    @foreach ($workerRows as $worker)
                                        <tr wire:key="worker-{{ $worker['id'] }}">
                                            <td class="px-3 py-2 font-medium text-brand-ink sm:px-4">{{ $worker['name'] }}</td>
                                            <td class="px-2 py-2 break-all font-mono text-xs text-brand-moss">{{ $worker['command'] }}</td>
                                            <td class="px-2 py-2 font-mono text-xs text-brand-moss">{{ $worker['concurrency'] }}</td>
                                            <td class="px-2 py-2 text-xs text-brand-moss">{{ $worker['restart_policy'] }}</td>
                                            <td class="px-2 py-2">
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em]',
                                                    'bg-emerald-100 text-emerald-900' => $worker['status'] === 'running',
                                                    'bg-rose-100 text-rose-900' => $worker['status'] === 'erroring',
                                                    'bg-sky-100 text-sky-900' => $worker['status'] === 'pending',
                                                    'bg-amber-100 text-amber-900' => $worker['status'] === 'idle',
                                                    'bg-slate-100 text-slate-700' => $worker['status'] === 'stopped',
                                                ])>{{ $worker['status_label'] }}</span>
                                            </td>
                                            <td class="px-3 py-2 sm:px-4">
                                                <div class="flex items-center justify-end gap-2.5 text-xs font-semibold">
                                                    <button type="button" wire:click="toggleWorker('{{ $worker['id'] }}')" class="text-brand-ink hover:underline">
                                                        {{ $worker['enabled'] ? __('Disable') : __('Enable') }}
                                                    </button>
                                                    <button type="button" wire:click="editWorker('{{ $worker['id'] }}')" class="text-brand-ink hover:underline">
                                                        {{ __('Edit') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('deleteWorker', @js([(string) $worker['id']]), @js(__('Remove worker')), @js(__('Remove the worker “:name”? This only deletes the definition — it does not stop a running process by itself.', ['name' => $worker['name']])), @js(__('Remove')), true)"
                                                        class="text-rose-700 hover:underline"
                                                    >
                                                        {{ __('Remove') }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
                    <x-cli-snippet :commands="[
                        ['label' => __('Engine state + worker list'), 'command' => 'dply serverless workers '.$site->slug],
                        ['label' => __('Turn the queue engine on / off'), 'command' => 'dply serverless workers '.$site->slug.' --enable'],
                        ['label' => __('Fire one queue tick now'), 'command' => 'dply serverless workers '.$site->slug.' --tick'],
                        ['label' => __('Define a worker'), 'command' => 'dply serverless workers '.$site->slug.' --add queue-default --command \''.$workerCommandPlaceholder.'\''],
                        ['label' => __('Stop / start / remove one'), 'command' => 'dply serverless workers '.$site->slug.' --stop queue-default'],
                    ]" />
                </div>
            </section>
        </main>
    </div>

    @include('livewire.sites.partials.tick-detail-modal')
    @include('livewire.partials.confirm-action-modal')

    @if ($showWorkerForm)
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            x-data
            x-on:keydown.escape.window="$wire.cancelWorkerForm()"
        >
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="cancelWorkerForm"></div>

            <div class="relative flex w-full max-w-lg flex-col rounded-2xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-brand-ink">
                            {{ $editingWorkerId ? __('Edit worker') : __('Add worker') }}
                        </h3>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Command, replica count, and restart policy for this app.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="cancelWorkerForm"
                        class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        aria-label="{{ __('Close') }}"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </header>

                <form wire:submit="saveWorker" class="space-y-3 px-4 py-3.5 sm:px-5">
                    <div>
                        <x-input-label for="workerName" :value="__('Name')" />
                        <x-text-input id="workerName" wire:model="workerName" class="mt-1 block w-full" placeholder="queue-default" />
                        <x-input-error :messages="$errors->get('workerName')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="workerCommand" :value="__('Command or function-ref')" />
                        <x-text-input id="workerCommand" wire:model="workerCommand" class="mt-1 block w-full font-mono text-sm" :placeholder="$workerCommandPlaceholder" />
                        <x-input-error :messages="$errors->get('workerCommand')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="workerConcurrency" :value="__('Replicas / max concurrency')" />
                            <x-text-input id="workerConcurrency" type="number" min="1" max="50" wire:model="workerConcurrency" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('workerConcurrency')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="workerRestartPolicy" :value="__('Restart policy')" />
                            <x-select id="workerRestartPolicy" wire:model="workerRestartPolicy" class="mt-1 block w-full">
                                @foreach ($restartPolicies as $policy)
                                    <option value="{{ $policy }}">{{ $policy }}</option>
                                @endforeach
                            </x-select>
                            <x-input-error :messages="$errors->get('workerRestartPolicy')" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-brand-ink/10 pt-3">
                        <button
                            type="button"
                            wire:click="cancelWorkerForm"
                            class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                        >
                            {{ $editingWorkerId ? __('Save changes') : __('Add worker') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
