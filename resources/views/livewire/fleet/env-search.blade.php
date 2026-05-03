<div class="mx-auto max-w-6xl px-6 py-10">
    @include('livewire.fleet._tabs')
    <header class="mb-6 border-b border-slate-200 pb-4">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('Fleet env search') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('Find environment variables across every site in this organization. Use prefix mode to enumerate all keys with a shared namespace.') }}</p>
    </header>

    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-[20rem] flex-1">
            <label for="env_query" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Key') }}</label>
            <input id="env_query" type="search" wire:model.live.debounce.300ms="query" placeholder="DATABASE_URL" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500" />
        </div>
        <div>
            <label for="env_mode" class="block text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Match') }}</label>
            <select id="env_mode" wire:model.live="mode" class="mt-1 rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500">
                <option value="exact">{{ __('Exact key') }}</option>
                <option value="prefix">{{ __('Prefix') }}</option>
            </select>
        </div>
        <button type="button" wire:click="toggleReveal" class="self-end rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            {{ $reveal ? __('Hide values') : __('Reveal values') }}
        </button>
    </div>

    @if (! $hasQuery)
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('Enter a key (or prefix) above to search.') }}
        </div>
    @elseif ($rows === [])
        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-600">
            {{ __('No matches across the fleet.') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Environment') }}</th>
                        <th class="px-4 py-3">{{ __('Key') }}</th>
                        <th class="px-4 py-3">{{ $reveal ? __('Value') : __('Value (masked)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-slate-700">{{ $row['site']->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['environment'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-800">{{ $row['key'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ trans_choice('{1} 1 match|[2,*] :count matches', count($rows), ['count' => count($rows)]) }}</p>
    @endif

    <footer class="mt-8 text-xs text-slate-500">
        {{ __('Same data is available from the terminal:') }}
        <code class="ml-1 select-all rounded bg-slate-100 px-1 py-0.5 font-mono">dply:fleet:env-find {{ $query !== '' ? $query : 'KEY' }}{{ $mode === 'prefix' ? ' --prefix' : '' }}</code>
    </footer>
</div>
