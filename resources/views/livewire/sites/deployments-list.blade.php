<div class="mx-auto max-w-6xl px-6 py-10">
    <nav class="mb-4 text-sm text-slate-500">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:text-slate-700">{{ $site->name }}</a>
        <span class="mx-2 text-slate-400">/</span>
        <span class="text-slate-700">{{ __('Deployments') }}</span>
    </nav>

    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Deployments') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Every deployment recorded for this site, newest first. Click a row to drill into per-step output.') }}</p>
    </header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="status_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Status') }}</label>
            <select id="status_filter" wire:model.live="statusFilter" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="trigger_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Trigger') }}</label>
            <select id="trigger_filter" wire:model.live="triggerFilter" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="">{{ __('Any') }}</option>
                @foreach ($triggers as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </div>
        @if ($statusFilter !== '' || $triggerFilter !== '')
            <button type="button" wire:click="clearFilters" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Clear filters') }}
            </button>
        @endif
        <p class="ml-auto text-xs text-slate-500">{{ trans_choice('{1} :count deployment|[2,*] :count deployments', $deployments->total(), ['count' => $deployments->total()]) }}</p>
    </div>

    @if ($deployments->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('No deployments match the current filters.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Started') }}</th>
                        <th class="px-4 py-3">{{ __('Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Phases') }}</th>
                        <th class="px-4 py-3">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($deployments as $deployment)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em]',
                                    'bg-emerald-100 text-emerald-900' => $deployment->status === 'success',
                                    'bg-rose-100 text-rose-900' => $deployment->status === 'failed',
                                    'bg-amber-100 text-amber-900' => $deployment->status === 'running',
                                    'bg-slate-100 text-slate-700' => ! in_array($deployment->status, ['success', 'failed', 'running']),
                                ])>{{ $deployment->status }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if ($deployment->started_at)
                                    <span title="{{ $deployment->started_at->toIso8601String() }}">{{ $deployment->started_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                @if ($deployment->phaseTotalDurationMs() > 0)
                                    {{ number_format($deployment->phaseTotalDurationMs() / 1000, 1) }}s
                                @elseif ($deployment->started_at && $deployment->finished_at)
                                    {{ $deployment->started_at->diffInSeconds($deployment->finished_at) }}s
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ $deployment->trigger ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (['build', 'swap', 'release', 'restart'] as $phase)
                                        @if ($deployment->hasPhase($phase))
                                            <span @class([
                                                'inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em]',
                                                'bg-emerald-50 text-emerald-800' => $deployment->phaseOk($phase),
                                                'bg-rose-50 text-rose-800' => ! $deployment->phaseOk($phase),
                                            ])>{{ $phase }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate class="select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 hover:bg-slate-200 hover:text-slate-700">{{ $deployment->id }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $deployments->links() }}
        </div>
    @endif

    <footer class="mt-6 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:site:deploy-history {{ $site->slug }}</code>
    </footer>
</div>
