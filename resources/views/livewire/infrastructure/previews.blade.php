<div>
    <x-infrastructure-shell
        :title="__('Preview URLs')"
        :description="__('Managed preview hostnames across BYO and Edge share one pattern — :primary for production previews, :branch for branch/PR previews — usually on :apex.', [
            'primary' => $patternPrimary,
            'branch' => $patternBranch,
            'apex' => $preferredApex,
        ])"
        :section="__('Previews')"
        icon="heroicon-o-link"
    >
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            <label class="sr-only" for="preview-search">{{ __('Search previews') }}</label>
            <input
                id="preview-search"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search hostname or site…') }}"
                class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
            />
            @foreach (['' => __('All'), 'byo' => __('BYO'), 'edge' => __('Edge')] as $value => $label)
                <x-infrastructure-pill :active="$productFilter === $value" wire:click="$set('productFilter', '{{ $value }}')">{{ $label }}</x-infrastructure-pill>
            @endforeach
            <span class="ms-auto text-xs text-brand-moss">{{ trans_choice(':count preview|:count previews', $total, ['count' => $total]) }}</span>
        </div>

        @if ($rows === [])
            <x-infrastructure-empty :title="__('No managed preview hostnames yet.')">
                <p class="mt-1">{{ __('BYO sites get testing hostnames after provision; Edge sites publish to on-dply delivery domains.') }}</p>
            </x-infrastructure-empty>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-brand-ink/10 bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                        <tr>
                            <th class="px-5 py-3 sm:px-6">{{ __('Hostname') }}</th>
                            <th class="px-4 py-3">{{ __('Site') }}</th>
                            <th class="px-4 py-3">{{ __('Engine') }}</th>
                            <th class="px-4 py-3">{{ __('Kind') }}</th>
                            <th class="px-5 py-3 sm:px-6">{{ __('Apex') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-brand-sand/20">
                                <td class="px-5 py-3 font-mono text-xs text-brand-ink sm:px-6">
                                    <a href="https://{{ $row['hostname'] }}" target="_blank" rel="noopener noreferrer" class="text-brand-sage hover:text-brand-forest">{{ $row['hostname'] }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row['href'])
                                        <a href="{{ $row['href'] }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-forest">{{ $row['site_name'] }}</a>
                                    @else
                                        <span class="font-semibold text-brand-ink">{{ $row['site_name'] }}</span>
                                    @endif
                                    @if ($row['parent_name'])
                                        <p class="text-xs text-brand-moss">{{ __('Preview of :parent', ['parent' => $row['parent_name']]) }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold uppercase text-brand-moss">{{ $row['product'] }}</td>
                                <td class="px-4 py-3 text-brand-moss">{{ str_replace('_', ' ', $row['kind']) }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-brand-moss sm:px-6">{{ $row['apex'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-infrastructure-shell>
</div>
