<div class="mx-auto max-w-7xl px-6 py-10">
    @include('livewire.fleet._tabs')

    <header class="mb-6 border-b border-brand-ink/10 pb-4">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Preview URLs') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-brand-moss">
            {{ __('Managed preview hostnames across BYO and Edge share one pattern — :primary for production previews, :branch for branch/PR previews — usually on :apex.', [
                'primary' => $patternPrimary,
                'branch' => $patternBranch,
                'apex' => $preferredApex,
            ]) }}
        </p>
    </header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <label class="sr-only" for="preview-search">{{ __('Search previews') }}</label>
        <input
            id="preview-search"
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search hostname or site…') }}"
            class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
        />
        @foreach (['' => __('All'), 'byo' => __('BYO'), 'edge' => __('Edge')] as $value => $label)
            <button type="button" wire:click="$set('productFilter', '{{ $value }}')"
                @class([
                    'rounded-full border px-3 py-1 text-xs font-semibold transition',
                    'border-brand-ink bg-brand-ink text-brand-cream' => $productFilter === $value,
                    'border-brand-ink/15 bg-white text-brand-moss hover:text-brand-ink' => $productFilter !== $value,
                ])>
                {{ $label }}
            </button>
        @endforeach
        <span class="ms-auto text-xs text-brand-moss">{{ trans_choice(':count preview|:count previews', $total, ['count' => $total]) }}</span>
    </div>

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No managed preview hostnames yet.') }}</p>
            <p class="mt-1">{{ __('BYO sites get testing hostnames after provision; Edge sites publish to on-dply delivery domains.') }}</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="border-b border-brand-ink/10 bg-brand-sand/30 text-left text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">
                    <tr>
                        <th class="px-4 py-3">{{ __('Hostname') }}</th>
                        <th class="px-4 py-3">{{ __('Site') }}</th>
                        <th class="px-4 py-3">{{ __('Engine') }}</th>
                        <th class="px-4 py-3">{{ __('Kind') }}</th>
                        <th class="px-4 py-3">{{ __('Apex') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-brand-sand/20">
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink">
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
                            <td class="px-4 py-3 uppercase text-xs font-semibold text-brand-moss">{{ $row['product'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ str_replace('_', ' ', $row['kind']) }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $row['apex'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
