<x-section-card>
    <h3 class="text-base font-semibold text-brand-ink">{{ __('How to use access') }}</h3>
    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('Keep access here as narrow as possible. Add only the people who should work on this project. Use owners for long-term accountability, maintainers for day-to-day changes, deployers for release execution, and viewers for read-only visibility.') }}</p>
</x-section-card>

<x-section-card>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h3 class="text-base font-semibold text-brand-ink">{{ __('Access') }}</h3>
        <span class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Strict project membership') }}</span>
    </div>

    <div class="mb-5 space-y-3">
        @foreach ($workspace->members as $member)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 px-3 py-3">
                <div>
                    <p class="font-medium text-brand-ink">{{ $member->user?->name }}</p>
                    <p class="text-sm text-brand-moss">{{ $member->user?->email }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <x-badge tone="accent" :caps="false">{{ ucfirst($member->role) }}</x-badge>
                    @if ($workspace->userCanManageMembers(auth()->user()))
                        <button type="button" wire:click="removeMember('{{ $member->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if ($workspace->userCanManageMembers(auth()->user()))
        <div class="mb-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
            {{ __('Add organization members here when they should be able to work inside this project. If someone only needs access to one customer or one stack, prefer project membership over broader organization permissions.') }}
        </div>
        <div class="grid gap-3 md:grid-cols-3">
            <div class="md:col-span-2">
                <x-input-label for="member-user" :value="__('Add member')" />
                <x-select id="member-user" wire:model="memberUserId">
                    <option value="">{{ __('Choose organization member') }}</option>
                    @foreach ($orgUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label for="member-role" :value="__('Role')" />
                <x-select id="member-role" wire:model="memberRole">
                    @foreach ($workspaceRoles as $role)
                        <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                    @endforeach
                </x-select>
            </div>
        </div>
        <div class="mt-3">
            <x-secondary-button type="button" wire:click="addMember">{{ __('Save member') }}</x-secondary-button>
        </div>
    @endif
</x-section-card>
