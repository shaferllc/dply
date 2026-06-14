@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $memberCount = $organization->users->count();
    $invitationCount = $organization->invitations->count();
    $teamCount = $organization->teams->count();

    // Role tone tokens for the trailing chip. Owner / admin pop; member /
    // deployer stay neutral so the list reads as a calm directory.
    $roleClasses = function (string $role): string {
        return match (strtolower($role)) {
            'owner' => 'border-brand-sage/35 bg-brand-sage/15 text-brand-forest',
            'admin' => 'border-amber-200 bg-amber-50 text-amber-900',
            'deployer' => 'border-sky-200 bg-sky-50 text-sky-700',
            default => 'border-brand-ink/10 bg-brand-sand/50 text-brand-moss',
        };
    };
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="members" :breadcrumb="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
            ['label' => __('Members'), 'icon' => 'user-group'],
        ]">
            <x-livewire-validation-errors />

            {{-- Hero: positioning + at-a-glance counts. --}}
            <x-hero-card
                :eyebrow="__('People & access')"
                :title="__('Members')"
                :description="__('Invite people by email, track pending invitations, and see everyone with access to this organization.')"
                icon="user-group"
                iconSize="md"
            >
                <x-docs-link slug="org-roles-and-limits" size="md">
                    <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Roles & limits') }}
                </x-docs-link>
                <x-outline-link href="{{ route('organizations.teams', $organization) }}" wire:navigate>
                    <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Teams') }}
                </x-outline-link>
                @if ($isAdmin)
                    <button
                        type="button"
                        wire:click="openInviteModal"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Invite member') }}
                    </button>
                @endif

                <x-slot:stats>
                    <dl class="grid grid-cols-3 gap-2">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $memberCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('total|total', $memberCount) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('With access') }}</p>
                        </div>
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-amber-200 bg-amber-50' => $invitationCount > 0,
                            'border-brand-ink/10 bg-white' => $invitationCount === 0,
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invites') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $invitationCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('pending|pending', $invitationCount) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Not yet accepted') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $teamCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('team|teams', $teamCount) }}</span>
                            </dd>
                            <a href="{{ route('organizations.teams', $organization) }}" wire:navigate class="mt-1 inline-flex text-[11px] font-semibold text-brand-sage hover:text-brand-ink">{{ __('Manage') }} →</a>
                        </div>
                    </dl>
                </x-slot:stats>
            </x-hero-card>

            <div class="mt-6 space-y-6">
                {{-- Pending invitations. Surface only when there's something
                     pending — collapsing the section when empty keeps the
                     page focused on the actual member directory. --}}
                @if ($invitationCount > 0)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-envelope class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Outstanding') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pending invitations') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('People you\'ve invited but who haven\'t accepted yet.') }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-amber-900 ring-1 ring-amber-200">{{ $invitationCount }}</span>
                        </div>
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($organization->invitations as $inv)
                                <li class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $inv->email }}</span>
                                            <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $roleClasses($inv->role) }}">{{ $inv->role }}</span>
                                        </div>
                                        <p class="mt-0.5 text-[11px] text-brand-mist">
                                            @if ($inv->expires_at)
                                                {{ __('Expires :time', ['time' => $inv->expires_at->diffForHumans()]) }}
                                            @else
                                                {{ __('Awaiting acceptance') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if ($isAdmin)
                                        <button
                                            type="button"
                                            wire:click="promptCancelInvitation('{{ $inv->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                        >
                                            <x-heroicon-o-x-mark class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Cancel') }}
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Member directory --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-users class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Directory') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Members') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Roles control what each person can change. Deployers have a reduced scope.') }}</p>
                        </div>
                        @if ($isAdmin)
                                <button
                                    type="button"
                                    wire:click="openInviteModal"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Invite') }}
                                </button>
                            @endif
                    </div>

                    @if ($organization->users->isEmpty())
                        <div class="px-6 py-12 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No members yet.') }}</p>
                            @if ($isAdmin)
                                <button type="button" wire:click="openInviteModal" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Invite the first one') }} →</button>
                            @endif
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($organization->users as $user)
                                @php $initials = collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') ?: mb_substr((string) ($user->email ?? '?'), 0, 1); @endphp
                                <li class="flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-sand/55 text-xs font-semibold text-brand-forest ring-1 ring-brand-ink/10">
                                        {{ strtoupper($initials) }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $user->name }}</p>
                                        <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $user->email }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $roleClasses($user->pivot->role) }}">{{ $user->pivot->role }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </x-organization-shell>
    </div>

    @if ($isAdmin)
        <x-modal
            name="invite-member-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="inviteMember">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-user-plus class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invite member') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Send an invitation') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('We\'ll email them a link to join :org.', ['org' => $organization->name]) }}
                        </p>
                    </div>
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
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Owner can\'t be assigned here — only Admin, Member, and Deployer.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeInviteModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="inviteMember">
                        <span wire:loading.remove wire:target="inviteMember" class="inline-flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Send invitation') }}
                        </span>
                        <span wire:loading wire:target="inviteMember" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
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
