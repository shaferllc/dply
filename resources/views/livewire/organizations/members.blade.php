<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="members">
            <x-livewire-validation-errors />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="p-6 sm:p-8">
                        <div class="max-w-3xl">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Members') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Invite people by email, track pending invitations, and see everyone with access to this organization.') }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Directory') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Roles control what members can change. Deployers have a reduced scope.') }}</p>
                            <p class="mt-4 text-xs text-brand-mist">
                                <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Roles & limits') }}</a>
                            </p>
                        </div>
                        <div class="lg:col-span-8 space-y-6 min-w-0">
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <form wire:submit="inviteMember" class="flex flex-wrap items-end gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                    <div class="min-w-[12rem] flex-1">
                                        <label for="invite_email" class="sr-only">{{ __('Email') }}</label>
                                        <input
                                            type="email"
                                            id="invite_email"
                                            wire:model="invite_email"
                                            placeholder="{{ __('Email to invite') }}"
                                            required
                                            class="block w-full rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        @error('invite_email')
                                            <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div>
                                        <label for="invite_role" class="sr-only">{{ __('Role') }}</label>
                                        <select id="invite_role" wire:model="invite_role" class="rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                                            <option value="member">{{ __('Member') }}</option>
                                            <option value="admin">{{ __('Admin') }}</option>
                                            <option value="deployer">{{ __('Deployer') }}</option>
                                        </select>
                                    </div>
                                    <x-primary-button type="submit" class="!text-sm">{{ __('Invite') }}</x-primary-button>
                                </form>
                            @endif

                            @if ($organization->invitations->isNotEmpty())
                                <div>
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Pending invitations') }}</p>
                                    <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                        @foreach ($organization->invitations as $inv)
                                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                                <span class="text-brand-ink">{{ $inv->email }} <span class="text-brand-moss">({{ $inv->role }})</span></span>
                                                @if ($organization->hasAdminAccess(auth()->user()))
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('cancelInvitation', ['{{ $inv->id }}'], @js(__('Cancel invitation')), @js(__('Cancel this invitation?')), @js(__('Cancel invitation')), true)"
                                                        class="text-sm font-medium text-red-600 hover:underline"
                                                    >{{ __('Cancel') }}</button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                @foreach ($organization->users as $user)
                                    <li class="flex items-center justify-between gap-4 px-4 py-3">
                                        <div class="min-w-0">
                                            <span class="font-medium text-brand-ink">{{ $user->name }}</span>
                                            <span class="ml-2 text-sm text-brand-moss">{{ $user->email }}</span>
                                        </div>
                                        <span class="shrink-0 text-xs font-semibold uppercase text-brand-moss">{{ $user->pivot->role }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
