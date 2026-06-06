<div>
    <x-fleet-shell
        :title="__('Fleet domains')"
        :description="__('Every hostname attached to a site in this organization. Search to locate where a domain is served.')"
        :section="__('Domains')"
    >
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <label for="domain_search" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Search') }}</label>
            <input id="domain_search" type="search" wire:model.live.debounce.250ms="search" placeholder="example.com" class="dply-input" />
        </div>
        <div>
            <label for="runtime_filter" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Runtime') }}</label>
            <select id="runtime_filter" wire:model.live="runtimeFilter" class="dply-input">
                <option value="">{{ __('Any') }}</option>
                @foreach ($runtimes as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 self-end pb-2.5 text-sm text-brand-moss">
            <input type="checkbox" wire:model.live="primaryOnly" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Primary only') }}
        </label>
        @if ($search !== '' || $runtimeFilter !== '' || $primaryOnly)
            <button type="button" wire:click="clearFilters" class="self-end rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                {{ __('Clear filters') }}
            </button>
        @endif
        <p class="ml-auto self-end pb-2.5 text-xs text-brand-mist">{{ trans_choice('{1} :count domain|[2,*] :count domains', count($rows), ['count' => count($rows)]) }}</p>
    </div>

    @if ($rows === [])
        <x-fleet-empty>{{ __('No domains match the current filters.') }}</x-fleet-empty>
    @else
        <div class="overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">
                    <tr>
                        <th class="px-4 py-3">{{ __('Hostname') }}</th>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Runtime') }}</th>
                        <th class="px-4 py-3">{{ __('Server') }}</th>
                        <th class="px-4 py-3">{{ __('Primary') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-brand-sand/20">
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink">{{ $row['hostname'] }}</td>
                            <td class="px-4 py-3 text-brand-ink">
                                @if ($row['server'])
                                    <a href="{{ route('sites.show', ['server' => $row['server'], 'site' => $row['site']]) }}" wire:navigate class="hover:text-brand-forest">{{ $row['site']->name }}</a>
                                @else
                                    {{ $row['site']->name }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['site']->runtime ?: '—' }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['server']?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($row['is_primary'])
                                    <span class="text-amber-500" title="{{ __('Primary') }}">★</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-cli-snippet class="mt-8" :commands="[
        ['label' => __('List all'), 'command' => 'dply fleet:domains:list'],
        ['label' => __('Find by hostname'), 'command' => 'dply fleet:domains:find example.com'],
    ]" />
    </x-fleet-shell>
</div>
