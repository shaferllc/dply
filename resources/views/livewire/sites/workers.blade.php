<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Workers') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9" wire:poll.15s>
            <x-page-header
                :title="__('Workers')"
                :description="__('Long-running engine processes — queue consumers and background workers tied to this app.')"
                doc-route="docs.index"
                flush
                compact
            />

            @if ($secretMismatchDetected)
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
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="redeployToRefreshSecret" />
                                <span wire:loading.remove wire:target="redeployToRefreshSecret">{{ __('Redeploy to refresh secret') }}</span>
                                <span wire:loading wire:target="redeployToRefreshSecret">{{ __('Queueing…') }}</span>
                            </button>
                        </div>
                    </div>
                </section>
            @endif

            @if (($dns['status'] ?? null) === 'failed')
                <section class="dply-card overflow-hidden border-rose-200">
                    <div class="border-b border-brand-ink/10 bg-rose-50/60 px-6 py-5 sm:px-7">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-50 text-rose-700 ring-rose-200">
                                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('DNS') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('DNS provisioning failed') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                        {{ __('Common causes: the token doesn\'t own the zone in DigitalOcean, the zone hasn\'t been created on DO yet, or a transient API error. Verify in the DigitalOcean dashboard, then retry.') }}
                                    </p>
                                    <p class="mt-2 break-all font-mono text-xs text-rose-700">
                                        {{ $dns['error'] ?? __('No error detail recorded.') }}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="provisionDnsNow"
                                wire:loading.attr="disabled"
                                wire:target="provisionDnsNow"
                                class="inline-flex shrink-0 items-center gap-1.5 self-start whitespace-nowrap rounded-xl bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 disabled:cursor-wait disabled:opacity-60 sm:self-auto"
                            >
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.class="animate-spin" wire:target="provisionDnsNow" aria-hidden="true" />
                                <span wire:loading.remove wire:target="provisionDnsNow">{{ __('Retry DNS') }}</span>
                                <span wire:loading wire:target="provisionDnsNow">{{ __('Retrying…') }}</span>
                            </button>
                        </div>
                    </div>
                </section>
            @endif

            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between sm:p-8">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Process queue jobs in background ticks') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('When enabled, the same minute-cadence tick that drives Schedule also drains the queue. Future versions will let you define multiple named workers (command + concurrency + restart policy + live status).') }}
                        </p>
                        @if ($lastTickAt)
                            <p class="mt-2 text-xs text-brand-moss">
                                {{ __('Last tick:') }} <span class="font-mono">{{ \Illuminate\Support\Carbon::parse($lastTickAt)->diffForHumans() }}</span>
                            </p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-3">
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
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            title="{{ __('Fire one queue ping immediately, without waiting for the next cron interval.') }}"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5" wire:loading.class="animate-pulse" wire:target="tickNow" />
                            <span wire:loading.remove wire:target="tickNow">{{ __('Tick now') }}</span>
                            <span wire:loading wire:target="tickNow">{{ __('Ticking…') }}</span>
                        </button>
                    </div>
                </div>
            </section>

            @php
                $latestQueue = $queueHistory->first();
            @endphp
            @if ($latestQueue)
                <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <header class="flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Latest output') }}</h2>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('Most recent queue invocation — the function\'s response body, captured by the tick command. Refreshes every 15 seconds.') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 font-semibold uppercase tracking-[0.12em]',
                                'bg-emerald-100 text-emerald-900' => ($latestQueue['status'] ?? '') === 'ok',
                                'bg-rose-100 text-rose-900' => ($latestQueue['status'] ?? '') !== 'ok',
                            ])>{{ $latestQueue['status'] ?? 'unknown' }}</span>
                            @if (! empty($latestQueue['http_status']))
                                <span class="font-mono text-brand-moss">HTTP {{ $latestQueue['http_status'] }}</span>
                            @endif
                            <span class="font-mono text-brand-moss">{{ (int) ($latestQueue['duration_ms'] ?? 0) }}ms</span>
                            <span class="text-brand-moss" title="{{ $latestQueue['at'] ?? '' }}">{{ \Illuminate\Support\Carbon::parse($latestQueue['at'])->diffForHumans() }}</span>
                        </div>
                    </header>
                    @if (! empty($latestQueue['error']))
                        <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
                            <p class="font-semibold">{{ __('Error') }}</p>
                            <p class="mt-1 font-mono">{{ $latestQueue['error'] }}</p>
                        </div>
                    @endif
                    @php($body = trim((string) ($latestQueue['body_preview'] ?? '')))
                    @if ($body !== '')
                        <pre class="mt-4 max-h-[28rem] overflow-auto rounded-lg bg-slate-900 p-4 font-mono text-[11px] leading-relaxed text-slate-100">{{ $body }}</pre>
                    @else
                        <p class="mt-4 text-xs text-brand-moss">{{ __('No response body captured.') }}</p>
                    @endif
                </section>
            @endif

            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <header class="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Firing history') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Last 50 queue ticks. Newest first. Click a row to see its full output.') }}
                        </p>
                    </div>
                    <span class="text-xs text-brand-moss">{{ trans_choice('{0} no ticks yet|{1} :count tick recorded|[2,*] :count ticks recorded', $queueHistory->count(), ['count' => $queueHistory->count()]) }}</span>
                </header>

                @if ($queueHistory->isEmpty())
                    <div class="mt-4 rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                        @if ($queue_worker_enabled)
                            {{ __('No ticks recorded yet. dply runs the tick command every minute — the first row should land within ~60 seconds.') }}
                        @else
                            {{ __('Workers are disabled. Enable above and dply starts ticking every minute; rows appear here as they fire.') }}
                        @endif
                    </div>
                @else
                    <div class="mt-4 overflow-x-auto">
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
                                @foreach ($queueHistory as $entry)
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
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-start justify-between gap-4 p-6 sm:p-8">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Named workers') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Define the worker processes this app runs — command or function-ref, replicas, and restart policy. In v1 every enabled worker is driven by the single engine tick above; per-worker process isolation arrives in a later release.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="newWorker"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                        {{ __('Add worker') }}
                    </button>
                </div>

                @if (empty($workerRows))
                    <div class="border-t border-brand-ink/10 p-6 text-center text-sm text-brand-moss sm:p-8">
                        {{ __('No workers defined yet. Add one to describe the command, replica count, and restart policy dply should run.') }}
                    </div>
                @else
                    <div class="overflow-x-auto border-t border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">
                                <tr>
                                    <th class="px-6 py-2.5">{{ __('Worker') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Command / function-ref') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Replicas') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Restart') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Status') }}</th>
                                    <th class="px-6 py-2.5 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ($workerRows as $worker)
                                    <tr wire:key="worker-{{ $worker['id'] }}">
                                        <td class="px-6 py-3 font-medium text-brand-ink">{{ $worker['name'] }}</td>
                                        <td class="px-3 py-3 break-all font-mono text-xs text-brand-moss">{{ $worker['command'] }}</td>
                                        <td class="px-3 py-3 font-mono text-xs text-brand-moss">{{ $worker['concurrency'] }}</td>
                                        <td class="px-3 py-3 text-xs text-brand-moss">{{ $worker['restart_policy'] }}</td>
                                        <td class="px-3 py-3">
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em]',
                                                'bg-emerald-100 text-emerald-900' => $worker['status'] === 'running',
                                                'bg-rose-100 text-rose-900' => $worker['status'] === 'erroring',
                                                'bg-sky-100 text-sky-900' => $worker['status'] === 'pending',
                                                'bg-amber-100 text-amber-900' => $worker['status'] === 'idle',
                                                'bg-slate-100 text-slate-700' => $worker['status'] === 'stopped',
                                            ])>{{ $worker['status_label'] }}</span>
                                        </td>
                                        <td class="px-6 py-3">
                                            <div class="flex items-center justify-end gap-3 text-xs font-semibold">
                                                <button type="button" wire:click="toggleWorker('{{ $worker['id'] }}')" class="text-brand-ink hover:underline">
                                                    {{ $worker['enabled'] ? __('Disable') : __('Enable') }}
                                                </button>
                                                <button type="button" wire:click="editWorker('{{ $worker['id'] }}')" class="text-brand-ink hover:underline">
                                                    {{ __('Edit') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="deleteWorker('{{ $worker['id'] }}')"
                                                    wire:confirm="{{ __('Remove the worker ":name"?', ['name' => $worker['name']]) }}"
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
            </section>
        </main>
    </div>

    @include('livewire.sites.partials.tick-detail-modal')

    @if ($showWorkerForm)
        <div
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            x-data
            x-on:keydown.escape.window="$wire.cancelWorkerForm()"
        >
            <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="cancelWorkerForm"></div>

            <div class="relative flex w-full max-w-lg flex-col rounded-2xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-brand-ink/10 p-5">
                    <div>
                        <h3 class="text-base font-bold text-brand-ink">
                            {{ $editingWorkerId ? __('Edit worker') : __('Add worker') }}
                        </h3>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('A worker definition — the command, replica count, and restart policy dply records for this app.') }}
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

                <form wire:submit="saveWorker" class="space-y-4 p-5">
                    <div>
                        <x-input-label for="workerName" :value="__('Name')" />
                        <x-text-input id="workerName" wire:model="workerName" class="mt-1 block w-full" placeholder="queue-default" />
                        <x-input-error :messages="$errors->get('workerName')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="workerCommand" :value="__('Command or function-ref')" />
                        <x-text-input id="workerCommand" wire:model="workerCommand" class="mt-1 block w-full font-mono text-sm" placeholder="php artisan queue:work" />
                        <x-input-error :messages="$errors->get('workerCommand')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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

                    <div class="flex justify-end gap-2 border-t border-brand-ink/10 pt-4">
                        <button
                            type="button"
                            wire:click="cancelWorkerForm"
                            class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90"
                        >
                            {{ $editingWorkerId ? __('Save changes') : __('Add worker') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
