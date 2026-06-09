<div>
    <x-page-header
        :title="__('Organizations')"
        :description="__('Search all organizations, review override counts, and open org-specific flag tabs.')"
        flush
        compact
    />

    <div class="mb-6">
        <label for="org-search" class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Search') }}</label>
        <input id="org-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full max-w-md rounded-lg border-brand-ink/15 text-sm shadow-sm" placeholder="{{ __('Name, slug, or email…') }}" />
    </div>

    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
        <table class="min-w-full divide-y divide-brand-ink/10 text-left text-sm">
            <thead class="bg-brand-sand/40 text-xs uppercase tracking-wide text-brand-moss">
                <tr>
                    <th class="px-4 py-3 font-medium">{{ __('Organization') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Servers') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Sites') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Overrides') }}</th>
                    <th class="px-4 py-3 font-medium">{{ __('Created') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/5">
                @forelse ($organizations as $org)
                    <tr wire:key="admin-org-{{ $org->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.organizations.show', $org) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ $org->name }}</a>
                            <p class="font-mono text-[11px] text-brand-mist">{{ $org->slug }}</p>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format($org->servers_count) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format($org->sites_count) }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ number_format($overrideCounts[$org->id] ?? 0) }}</td>
                        <td class="px-4 py-3 text-xs text-brand-moss">{{ $org->created_at?->timezone(config('app.timezone'))->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-brand-mist">{{ __('No organizations match your search.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $organizations->links() }}</div>
</div>
