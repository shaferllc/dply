<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="members">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Members'), 'icon' => 'user-group'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-6 lg:gap-10 lg:items-center p-6 sm:p-8">
                        <div class="lg:col-span-5 xl:col-span-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('People & access') }}</p>
                            <h2 class="mt-2 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Members') }}</h2>
                            <p class="mt-3 max-w-xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Invite people by email, track pending invitations, and see everyone with access to this organization.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-7 xl:col-span-8">
                            <div class="flex flex-col gap-4 sm:ml-auto sm:max-w-lg lg:max-w-none">
                                <div class="flex flex-wrap items-center justify-end gap-2  p-2 sm:p-2.5">
                                    <a
                                        href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-2 rounded-xl border border-transparent bg-white/90 px-3 py-2 text-sm font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/10 transition hover:bg-white hover:ring-brand-sage/35 dark:bg-zinc-900/80 dark:ring-brand-mist/20 dark:hover:bg-zinc-900"
                                    >
                                        <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Roles & limits') }}
                                    </a>
                                    <a
                                        href="{{ route('docs.index') }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-2 rounded-xl border border-transparent bg-white/90 px-3 py-2 text-sm font-medium text-brand-ink shadow-sm ring-1 ring-brand-ink/10 transition hover:bg-white hover:ring-brand-sage/35 dark:bg-zinc-900/80 dark:ring-brand-mist/20 dark:hover:bg-zinc-900"
                                    >
                                        <x-heroicon-o-book-open class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                        {{ __('Documentation') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-6 lg:gap-10 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Member directory') }}</p>
                            <h3 class="mt-2 text-lg font-semibold text-brand-ink">{{ __('Directory') }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __('Roles control what members can change. Deployers have a reduced scope.') }}</p>
                            <p class="mt-4">
                                <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage underline decoration-brand-sage/35 underline-offset-2 transition hover:text-brand-ink hover:decoration-brand-ink/30">{{ __('Roles & limits') }} <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" /></a>
                            </p>
                        </div>
                        <div class="lg:col-span-8 min-w-0 space-y-5">
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <div class="flex justify-end">
                                    <x-primary-button type="button" wire:click="openInviteModal">
                                        <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Invite member') }}
                                    </x-primary-button>
                                </div>
                            @endif

                            <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900/80">
                                @if ($organization->invitations->isNotEmpty())
                                    <div class="border-b border-brand-ink/10 bg-brand-sand/35 px-4 py-2.5 dark:border-brand-mist/15 dark:bg-zinc-800/60">
                                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Pending invitations') }}</p>
                                    </div>
                                    <ul class="divide-y divide-brand-ink/10 dark:divide-brand-mist/15">
                                        @foreach ($organization->invitations as $inv)
                                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 text-sm transition-colors hover:bg-brand-sand/25 dark:hover:bg-zinc-800/50">
                                                <span class="min-w-0 text-brand-ink">
                                                    <span class="font-medium">{{ $inv->email }}</span>
                                                    <span class="ml-2 inline-flex items-center rounded-md bg-brand-sand/60 px-2 py-0.5 text-xs font-medium capitalize text-brand-moss dark:bg-zinc-700/80 dark:text-brand-mist">{{ $inv->role }}</span>
                                                </span>
                                                @if ($organization->hasAdminAccess(auth()->user()))
                                                    <button
                                                        type="button"
                                                        wire:click="promptCancelInvitation('{{ $inv->id }}')"
                                                        class="shrink-0 text-sm font-medium text-red-600 hover:text-red-700 hover:underline dark:text-red-400 dark:hover:text-red-300"
                                                    >{{ __('Cancel') }}</button>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <div @class([
                                    'border-b border-brand-ink/10 bg-brand-sand/35 px-4 py-2.5 dark:border-brand-mist/15 dark:bg-zinc-800/60',
                                    'border-t border-brand-ink/10 dark:border-brand-mist/15' => $organization->invitations->isNotEmpty(),
                                ])>
                                    <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Members') }}</p>
                                </div>
                                <ul class="divide-y divide-brand-ink/10 dark:divide-brand-mist/15">
                                    @foreach ($organization->users as $user)
                                        <li class="flex items-center justify-between gap-4 px-4 py-3.5 transition-colors hover:bg-brand-sand/25 dark:hover:bg-zinc-800/50">
                                            <div class="min-w-0">
                                                <span class="font-medium text-brand-ink">{{ $user->name }}</span>
                                                <span class="mt-0.5 block text-sm text-brand-moss sm:mt-0 sm:ml-2 sm:inline">{{ $user->email }}</span>
                                            </div>
                                            <span class="shrink-0 rounded-md bg-brand-sand/50 px-2 py-1 text-[0.65rem] font-semibold uppercase tracking-wide text-brand-moss dark:bg-zinc-800 dark:text-brand-mist">{{ $user->pivot->role }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>

    @if ($organization->hasAdminAccess(auth()->user()))
        <x-modal
            name="invite-member-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="inviteMember">
                <div class="border-b border-brand-ink/10 px-6 py-5 dark:border-brand-mist/20">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invite member') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Send an invitation') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        {{ __('We’ll email them a link to join :org.', ['org' => $organization->name]) }}
                    </p>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="invite_email_modal" :value="__('Email address')" />
                        <x-text-input
                            id="invite_email_modal"
                            wire:model="invite_email"
                            type="email"
                            class="mt-2 block w-full"
                            placeholder="{{ __('name@company.com') }}"
                            required
                            autocomplete="email"
                        />
                        <x-input-error :messages="$errors->get('invite_email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="invite_role_modal" :value="__('Role')" />
                        <x-select id="invite_role_modal" wire:model="invite_role" class="mt-2">
                            @foreach ($this->inviteableRoles() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Owner is not assigned here—only Admin, Member, and Deployer.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4 dark:border-brand-mist/20">
                    <x-secondary-button type="button" wire:click="closeInviteModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="inviteMember">
                        <span wire:loading.remove wire:target="inviteMember">{{ __('Send invitation') }}</span>
                        <span wire:loading wire:target="inviteMember" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Sending…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
