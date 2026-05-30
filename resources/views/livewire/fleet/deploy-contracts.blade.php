<div>
    <x-fleet-shell
        :title="__('Deploy contracts')"
        :description="__('Cross-engine promote gates for Edge previews — build, review, replay, Cloud origin, and linked BYO deploy health before production.')"
        :section="__('Deploy contracts')"
    >
        @if (! $contractEnabled)
            <x-fleet-empty :title="__('Deploy contract is disabled.')">
                <p class="mt-1">{{ __('Enable `global.deploy_contract` for this organization to use promote gates and this Fleet view.') }}</p>
            </x-fleet-empty>
        @elseif ($counts['total'] === 0)
            <x-fleet-empty :title="__('No Edge previews in this organization.')">
                <p class="mt-1">{{ __('Open an Edge site → Previews to deploy a branch preview, then run the deploy contract before promote.') }}</p>
            </x-fleet-empty>
        @else
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-fleet-stat :label="__('Previews')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['total'] }}</p>
                </x-fleet-stat>
                <x-fleet-stat :label="__('Ready to promote')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-emerald-600">{{ $counts['ready'] }}</p>
                </x-fleet-stat>
                <x-fleet-stat :label="__('Blocked')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-amber-600">{{ $counts['blocked'] }}</p>
                </x-fleet-stat>
                <x-fleet-stat :label="__('Not run yet')">
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">{{ $counts['not_run'] }}</p>
                </x-fleet-stat>
            </div>

            <div class="mb-6 flex flex-wrap items-center gap-3">
                <div class="min-w-[14rem] flex-1">
                    <label for="contract_search" class="block text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Search') }}</label>
                    <input id="contract_search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Site or branch') }}" class="dply-input" />
                </div>
                <div class="flex flex-wrap gap-2 self-end">
                    @foreach (['' => __('All'), 'blocked' => __('Blocked'), 'not_run' => __('Not run'), 'ready' => __('Ready')] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $value }}')"
                            @class([
                                'rounded-full px-3 py-1 text-xs font-semibold ring-1',
                                'bg-brand-forest text-white ring-brand-forest' => $filter === $value,
                                'bg-white text-brand-moss ring-brand-ink/15 hover:bg-brand-sand/40' => $filter !== $value,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($rows === [])
                <x-fleet-empty>{{ __('No previews match the current filters.') }}</x-fleet-empty>
            @else
                <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="px-4 py-3">{{ __('Production site') }}</th>
                                <th class="px-4 py-3">{{ __('Preview') }}</th>
                                <th class="px-4 py-3">{{ __('Contract') }}</th>
                                <th class="px-4 py-3">{{ __('Last run') }}</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-brand-sand/15">
                                    <td class="px-4 py-3 font-medium text-brand-ink">{{ $row['parent_name'] }}</td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-brand-ink">{{ $row['preview_name'] }}</span>
                                        @if ($row['branch'])
                                            <span class="mt-0.5 block font-mono text-xs text-brand-moss">{{ $row['branch'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($row['ready'])
                                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-900">{{ __('Ready') }}</span>
                                        @elseif ($row['status'] === null)
                                            <span class="inline-flex rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-semibold text-brand-moss">{{ __('Not run') }}</span>
                                        @elseif ($row['status'] === 'waived')
                                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ __('Waived') }}</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-900">{{ __('Failed (:n)', ['n' => $row['failed_count']]) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-brand-moss">
                                        {{ $row['run_at'] ? \Illuminate\Support\Carbon::parse($row['run_at'])->diffForHumans() : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ $row['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">
                                            {{ __('Open') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="mt-6 text-xs text-brand-moss">
                {{ __('Declare requirements in `dply-contract.yaml` or a `contract:` block in `dply.yaml` — synced on the next Edge build.') }}
            </p>
        @endif
    </x-fleet-shell>
</div>
