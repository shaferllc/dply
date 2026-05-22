<div class="mx-auto max-w-6xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Fleet deploys') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('In-flight, failed, and stagnant deploy activity across the fleet.') }}</p>
    </header>

    <nav class="mb-6 flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="{{ __('Deploy activity tabs') }}">
        <button type="button" wire:click="setTab('running')" @class([
            'rounded-xl px-3 py-1.5 text-sm font-medium transition',
            'bg-slate-900 text-white' => $tab === 'running',
            'border border-slate-200 text-slate-700 hover:bg-slate-50' => $tab !== 'running',
        ])>
            {{ __('Running') }} <span class="ml-1 text-xs text-slate-400">({{ $counts['running'] }})</span>
        </button>
        <button type="button" wire:click="setTab('failed-latest')" @class([
            'rounded-xl px-3 py-1.5 text-sm font-medium transition',
            'bg-rose-700 text-white' => $tab === 'failed-latest',
            'border border-slate-200 text-slate-700 hover:bg-slate-50' => $tab !== 'failed-latest',
        ])>
            {{ __('Failed latest') }} <span class="ml-1 text-xs text-rose-200">({{ $counts['failed-latest'] }})</span>
        </button>
        <button type="button" wire:click="setTab('stale')" @class([
            'rounded-xl px-3 py-1.5 text-sm font-medium transition',
            'bg-amber-600 text-white' => $tab === 'stale',
            'border border-slate-200 text-slate-700 hover:bg-slate-50' => $tab !== 'stale',
        ])>
            {{ __('Stale') }} <span class="ml-1 text-xs text-amber-200">({{ $counts['stale'] }})</span>
        </button>
        @if ($tab === 'stale')
            <div class="ml-auto flex items-center gap-2">
                <label for="stale_days" class="text-xs text-slate-600">{{ __('Days') }}</label>
                <input id="stale_days" type="number" min="1" wire:model.live.debounce.250ms="staleDays" class="w-20 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
            </div>
        @endif
    </nav>

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            @if ($tab === 'running')
                {{ __('No deploys are currently running.') }}
            @elseif ($tab === 'failed-latest')
                {{ __('No sites have a failed latest deploy.') }}
            @else
                {{ __('No sites with stale deploys (threshold: :days days).', ['days' => $staleDays]) }}
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Runtime') }}</th>
                        <th class="px-4 py-3">{{ __('When') }}</th>
                        <th class="px-4 py-3">{{ __('Age') }}</th>
                        <th class="px-4 py-3">{{ __('Trigger') }}</th>
                        <th class="px-4 py-3">{{ __('Deploy ID') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr @class([
                            'hover:bg-slate-50',
                            'bg-rose-50/50' => $row['severity'] === 'danger',
                            'bg-amber-50/50' => $row['severity'] === 'warning',
                        ])>
                            <td class="px-4 py-3 text-slate-700">
                                <a href="{{ route('sites.show', ['server' => $row['site']->server_id, 'site' => $row['site']]) }}" wire:navigate class="hover:underline">{{ $row['site']->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['site']->runtime ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['when'] ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $row['age_label'] }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['trigger'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('sites.deployments.show', ['server' => $row['site']->server_id, 'site' => $row['site'], 'deployment' => $row['deployment_id']]) }}" wire:navigate class="select-all rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-500 hover:bg-slate-200 hover:text-slate-700">{{ $row['deployment_id'] }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @php
        $fleetDeploysCommand = match ($tab) {
            'running' => 'dply:fleet:running-deploys',
            'failed-latest' => 'dply:fleet:failed-deploys',
            default => 'dply:fleet:stale-deploys --days='.$staleDays,
        };
    @endphp
    <x-cli-snippet class="mt-8" :command="$fleetDeploysCommand" />
</div>
