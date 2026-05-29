<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site members') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Grant viewer, deployer, or admin access to this Edge site without changing org-wide roles. Site grants only elevate permissions — they never restrict org admins.') }}
                </p>
            </div>
        </div>

        @can('manageMembers', $site)
            <form wire:submit="addMember" class="flex flex-wrap items-end gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <label class="min-w-[14rem] flex-1">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Organization member') }}</span>
                    <select wire:model="member_user_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="">{{ __('Select a member…') }}</option>
                        @foreach ($eligibleUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('member_user_id')" class="mt-1" />
                </label>
                <label class="w-48">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Role') }}</span>
                    <select wire:model="member_role" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addMember">
                    <span wire:loading.remove wire:target="addMember">{{ __('Add member') }}</span>
                    <span wire:loading wire:target="addMember">{{ __('Adding…') }}</span>
                </x-primary-button>
            </form>
        @endcan

        @if ($members->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">{{ __('No site-specific members yet. Org owners and admins retain full access.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($members as $member)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="edge-member-{{ $member->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ $member->user?->name ?? __('Unknown user') }}</p>
                            <p class="text-xs text-brand-moss">{{ $member->user?->email }}</p>
                            @if ($member->invitedBy)
                                <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Added by :name', ['name' => $member->invitedBy->name]) }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @can('manageMembers', $site)
                                <select
                                    class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink"
                                    wire:change="updateMemberRole('{{ $member->id }}', $event.target.value)"
                                >
                                    @foreach ($roleOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($member->role === $value)>{{ $value }}</option>
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    wire:click="removeMember('{{ $member->id }}')"
                                    class="text-xs font-semibold text-rose-700 hover:text-rose-900"
                                >
                                    {{ __('Remove') }}
                                </button>
                            @else
                                <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-forest">{{ $member->role }}</span>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
