<div class="mx-auto max-w-6xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Fleet domains') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Every hostname attached to a site in this organization. Search to locate where a domain is served.') }}</p>
    </header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <label for="domain_search" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Search') }}</label>
            <input id="domain_search" type="search" wire:model.live.debounce.250ms="search" placeholder="example.com" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
        </div>
        <div>
            <label for="runtime_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Runtime') }}</label>
            <select id="runtime_filter" wire:model.live="runtimeFilter" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="">{{ __('Any') }}</option>
                @foreach ($runtimes as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs text-slate-700">
            <input type="checkbox" wire:model.live="primaryOnly" class="rounded border-slate-300 text-slate-700 focus:ring-slate-500" />
            {{ __('Primary only') }}
        </label>
        @if ($search !== '' || $runtimeFilter !== '' || $primaryOnly)
            <button type="button" wire:click="clearFilters" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Clear filters') }}
            </button>
        @endif
        <p class="ml-auto text-xs text-slate-500">{{ trans_choice('{1} :count domain|[2,*] :count domains', count($rows), ['count' => count($rows)]) }}</p>
    </div>

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('No domains match the current filters.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Hostname') }}</th>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Runtime') }}</th>
                        <th class="px-4 py-3">{{ __('Server') }}</th>
                        <th class="px-4 py-3">{{ __('Primary') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $row['hostname'] }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                @if ($row['server'])
                                    <a href="{{ route('sites.show', ['server' => $row['server'], 'site' => $row['site']]) }}" wire:navigate class="hover:underline">{{ $row['site']->name }}</a>
                                @else
                                    {{ $row['site']->name }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['site']->runtime ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['server']?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($row['is_primary'])
                                    <span class="text-emerald-700">★</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:fleet:domain-list</code>
        {{ __('and') }}
        <code class="select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:fleet:domain-find</code>
    </footer>
</div>
