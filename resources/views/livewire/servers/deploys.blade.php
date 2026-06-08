<x-server-workspace-layout
    :server="$server"
    active="deploys"
    :title="__('Deploys')"
    :description="__('Every deployment recorded for sites on this server, newest first.')"
    :pageHeaderToolbar="true"
>
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
        @if ($statusFilter !== '')
            <button type="button" wire:click="clearFilters" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Clear filter') }}
            </button>
        @endif
        <p class="ml-auto text-xs text-slate-500">{{ trans_choice('{1} :count deployment|[2,*] :count deployments', $deployments->total(), ['count' => $deployments->total()]) }}</p>
    </div>

    @if ($deployments->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('No deployments match the current filter.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Started') }}</th>
                        <th class="px-4 py-3">{{ __('Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($deployments as $deployment)
                        @php($site = $sites->get($deployment->site_id))
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
                            <td class="px-4 py-3 text-slate-700">
                                @if ($site)
                                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}" wire:navigate class="hover:underline">{{ $site->name }}</a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
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
                                @if ($site)
                                    <a href="{{ route('sites.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}" wire:navigate class="select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 hover:bg-slate-200 hover:text-slate-700">{{ $deployment->id }}</a>
                                @else
                                    <span class="font-mono text-[10px] text-slate-400">{{ $deployment->id }}</span>
                                @endif
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

    @php($runningDeploysSnippet = "dply fleet:deploys:running --json | jq '.deployments[] | select(.server_id==\"{$server->id}\")'")
    <x-cli-snippet class="mt-8" :command="$runningDeploysSnippet" />
</x-server-workspace-layout>
