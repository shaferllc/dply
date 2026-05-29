<div>
    <x-fleet-shell
        :title="__('Fleet env search')"
        :description="__('Find environment variables across every site in this organization. Use prefix mode to enumerate all keys with a shared namespace.')"
        :section="__('Env search')"
    >
    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-[20rem] flex-1">
            <label for="env_query" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Key') }}</label>
            <input id="env_query" type="search" wire:model.live.debounce.300ms="query" placeholder="DATABASE_URL" class="dply-input font-mono" />
        </div>
        <div>
            <label for="env_mode" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Match') }}</label>
            <select id="env_mode" wire:model.live="mode" class="dply-input">
                <option value="exact">{{ __('Exact key') }}</option>
                <option value="prefix">{{ __('Prefix') }}</option>
            </select>
        </div>
        <button type="button" wire:click="toggleReveal" class="self-end rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
            {{ $reveal ? __('Hide values') : __('Reveal values') }}
        </button>
    </div>

    @if (! $hasQuery)
        <x-fleet-empty>{{ __('Enter a key (or prefix) above to search.') }}</x-fleet-empty>
    @elseif ($rows === [])
        <x-fleet-empty>{{ __('No matches across the fleet.') }}</x-fleet-empty>
    @else
        <div class="overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">
                    <tr>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Environment') }}</th>
                        <th class="px-4 py-3">{{ __('Key') }}</th>
                        <th class="px-4 py-3">{{ $reveal ? __('Value') : __('Value (masked)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-brand-sand/20">
                            <td class="px-4 py-3 text-brand-ink">{{ $row['site']->name }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['environment'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink">{{ $row['key'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-brand-mist">{{ trans_choice('{1} 1 match|[2,*] :count matches', count($rows), ['count' => count($rows)]) }}</p>
    @endif

    <x-cli-snippet class="mt-8" :command="'dply:fleet:env-find '.($query !== '' ? $query : 'KEY').($mode === 'prefix' ? ' --prefix' : '')" />
    </x-fleet-shell>
</div>
