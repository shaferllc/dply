<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Users') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Read-only inventory from `wp user list`. Creating, deleting, and role changes stay in the wp-admin / Console for now.') }}</p>
        </div>
        @if ($usersLoaded)
            <button type="button" wire:click="loadUsers" wire:loading.attr="disabled" wire:target="loadUsers" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadUsers" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadUsers" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
        @endif
    </div>

    @if (! $usersLoaded)
        <div wire:init="loadUsers" class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading users…') }}
        </div>
    @elseif (empty($users))
        <p class="px-6 py-8 text-sm text-brand-moss">{{ __('No users reported.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('ID') }}</th>
                        <th class="px-4 py-3">{{ __('Login') }}</th>
                        <th class="px-4 py-3">{{ __('Name') }}</th>
                        <th class="px-4 py-3">{{ __('Email') }}</th>
                        <th class="px-4 py-3">{{ __('Roles') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($users as $wpUser)
                        <tr wire:key="wp-user-{{ $wpUser['id'] }}">
                            <td class="px-4 py-3 font-mono text-xs text-brand-mist sm:px-6">{{ $wpUser['id'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink">{{ $wpUser['login'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $wpUser['name'] ?: '—' }}</td>
                            <td class="max-w-[14rem] truncate px-4 py-3 text-brand-moss" title="{{ $wpUser['email'] }}">{{ $wpUser['email'] ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @forelse (array_filter(array_map('trim', explode(',', $wpUser['roles']))) as $role)
                                        <span class="rounded-full bg-brand-ink/[0.05] px-2 py-0.5 text-[10px] font-medium text-brand-moss">{{ $role }}</span>
                                    @empty
                                        <span class="text-brand-mist">—</span>
                                    @endforelse
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-input-error :messages="$errors->get('users')" class="px-6 pb-4" />
</section>
