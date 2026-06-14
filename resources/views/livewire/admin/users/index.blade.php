<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-brand-ink">{{ __('Users') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Search any user and impersonate them to see the app from their perspective.') }}</p>
    </div>

    <input
        type="search"
        wire:model.live.debounce.300ms="search"
        placeholder="{{ __('Search by name or email…') }}"
        class="w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30"
    />

    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
            <thead class="bg-brand-cream/60 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                <tr>
                    <th class="px-4 py-2.5">{{ __('Name') }}</th>
                    <th class="px-4 py-2.5">{{ __('Email') }}</th>
                    <th class="px-4 py-2.5">{{ __('Organizations') }}</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/5">
                @forelse ($users as $user)
                    <tr class="hover:bg-brand-sand/20">
                        <td class="px-4 py-3 font-medium text-brand-ink">{{ $user->name }}</td>
                        <td class="px-4 py-3 text-brand-moss">{{ $user->email }}</td>
                        <td class="px-4 py-3 text-brand-moss">
                            {{ $user->organizations->pluck('name')->join(', ') ?: '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end">
                                <x-impersonate-button :user="$user" variant="subtle" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-brand-mist">{{ __('No users match that search.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $users->links() }}</div>
</div>
