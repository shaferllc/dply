@php
    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $memberCount = $organization->users->count();
    $invitationCount = $organization->invitations->count();
    $teams = $organization->teams;
    $teamCount = $teams->count();

    $activeTeam = $teamFilter === '' ? null : $teams->firstWhere('id', $teamFilter);
    $visibleMembers = $this->members();
    // Invitations follow the rail: a team invite belongs to that team's view,
    // and org-only invites only show under "All people".
    $visibleInvitations = $activeTeam
        ? $organization->invitations->where('team_id', $activeTeam->id)
        : $organization->invitations;

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

    // Avatar ring picks up the same tone, so seniority reads at a glance down
    // the left edge without adding another chip.
    $avatarClasses = fn (string $role): string => match (strtolower($role)) {
        'owner' => 'bg-brand-sage/20 text-brand-forest ring-brand-sage/35',
        'admin' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'deployer' => 'bg-sky-50 text-sky-700 ring-sky-200',
        default => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    };

    $initialsOf = fn ($user): string => strtoupper(
        collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')
            ?: mb_substr((string) ($user->email ?? '?'), 0, 1)
    );

    // Role headcount for the explainer strip — "who has the keys" is the
    // question this page exists to answer.
    $roleTally = $organization->users->groupBy(fn ($u) => strtolower($u->pivot->role))->map->count();

    $railBase = 'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors';
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="members"
            :title="__('People')"
            :description="__('Everyone with access to this organization, the role that decides what they can do, and the teams they sit on.')"
            icon="heroicon-o-user-group"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('People'), 'icon' => 'user-group'],
            ]"
        >
            <x-slot:actions>
                @if ($isAdmin)
                    <button
                        type="button"
                        wire:click="openInviteModal"
                        class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-user-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $activeTeam ? __('Invite to :team', ['team' => $activeTeam->name]) : __('Invite member') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('People at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $memberCount }}</dd>
                    </div>
                    <div @class([
                        'px-3 py-2',
                        'bg-amber-50/70' => $invitationCount > 0,
                        'bg-white' => $invitationCount === 0,
                    ])>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invites') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $invitationCount }}</span>
                            @if ($invitationCount > 0)
                                <span class="text-2xs font-semibold uppercase tracking-wide text-amber-800">{{ __('pending') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $teamCount }}</dd>
                    </div>
                </dl>
            </x-slot:stats>

            {{-- Who has the keys. Collapsible (remembered per org) so the role
                 vocabulary is one click away without living on the page. --}}
            <div
                class="border-b border-brand-ink/10 bg-brand-cream/40"
                x-data="{
                    _k: 'dply.members.howItWorksCollapsed:{{ $organization->id }}',
                    collapsed: false,
                    init() { try { this.collapsed = JSON.parse(localStorage.getItem(this._k)) || false; } catch (e) { this.collapsed = false; } },
                    toggle() { this.collapsed = ! this.collapsed; localStorage.setItem(this._k, JSON.stringify(this.collapsed)); },
                }"
            >
                <button
                    type="button"
                    x-on:click="toggle()"
                    :aria-expanded="(! collapsed).toString()"
                    class="flex w-full items-center gap-1.5 px-5 py-2 text-left sm:px-6"
                >
                    <span x-bind:class="collapsed ? '' : 'rotate-90'" class="inline-flex text-brand-mist transition-transform">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('What the roles mean') }}</span>
                    <span class="ms-auto text-2xs text-brand-mist" x-show="collapsed">{{ __('Show') }}</span>
                </button>

                <div x-show="! collapsed" x-collapse>
                    <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-4">
                        @foreach ([
                            ['role' => 'owner', 'blurb' => __('Owns billing and the organization itself. Can\'t be assigned by invite.')],
                            ['role' => 'admin', 'blurb' => __('Full control of servers, sites, and members — everything but ownership.')],
                            ['role' => 'member', 'blurb' => __('Day-to-day access to the infrastructure they\'re given.')],
                            ['role' => 'deployer', 'blurb' => __('Reduced scope: deploy and read, no destructive changes.')],
                        ] as $row)
                            <div class="bg-brand-cream/40 px-5 py-3 sm:px-4">
                                <dt class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($row['role']) }}">{{ $row['role'] }}</span>
                                    <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $roleTally[$row['role']] ?? 0 }}</span>
                                </dt>
                                <dd class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $row['blurb'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif

            {{-- Rail + directory. A team is a named group of members whose only
                 job is to scope notification channels — it grants no access — so
                 it belongs here as a filter over the one member list, not as a
                 page of its own with a second copy of that list. --}}
            <div class="flex flex-col lg:flex-row">
                <nav class="shrink-0 border-b border-brand-ink/10 bg-brand-cream/30 p-2 lg:w-56 lg:border-b-0 lg:border-e" aria-label="{{ __('Filter by team') }}">
                    <button
                        type="button"
                        wire:click="selectTeam"
                        @class([$railBase, 'w-full text-left', 'bg-brand-sand/50 font-semibold text-brand-ink' => ! $activeTeam, 'text-brand-moss hover:bg-brand-sand/25' => (bool) $activeTeam])
                    >
                        <x-heroicon-o-user-group class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ __('All people') }}</span>
                        <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $memberCount }}</span>
                    </button>

                    <p class="px-2 pb-1 pt-3 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Teams') }}</p>

                    @forelse ($teams as $team)
                        @php $isActive = $activeTeam && $activeTeam->id === $team->id; @endphp
                        <div @class([$railBase, '!py-0.5', 'bg-brand-sand/50' => $isActive, 'hover:bg-brand-sand/25' => ! $isActive])>
                            <button
                                type="button"
                                wire:click="selectTeam('{{ $team->id }}')"
                                @class(['flex min-w-0 flex-1 items-center gap-2 py-1 text-left', 'font-semibold text-brand-ink' => $isActive, 'text-brand-moss' => ! $isActive])
                            >
                                <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-80" aria-hidden="true" />
                                <span class="min-w-0 flex-1 truncate">{{ $team->name }}</span>
                                <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $team->users->count() }}</span>
                            </button>

                            @if ($isAdmin)
                                <x-dropdown align="right" width="17rem">
                                    <x-slot:trigger>
                                        <button
                                            type="button"
                                            class="inline-flex h-6 w-5 shrink-0 items-center justify-center rounded-md text-brand-mist transition-colors hover:bg-white hover:text-brand-ink"
                                            aria-label="{{ __('Team options for :team', ['team' => $team->name]) }}"
                                        >
                                            <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" aria-hidden="true" />
                                        </button>
                                    </x-slot:trigger>

                                    <x-slot:content>
                                        <x-dropdown-link href="{{ route('teams.notification-channels', [$organization, $team]) }}" wire:navigate :description="__('Where this team\'s alerts go.')">
                                            <x-slot:icon><x-heroicon-o-bell aria-hidden="true" /></x-slot:icon>
                                            {{ __('Notification channels') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link href="#" wire:click.prevent="openTeamModal('{{ $team->id }}')">
                                            <x-slot:icon><x-heroicon-o-pencil-square aria-hidden="true" /></x-slot:icon>
                                            {{ __('Rename team') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link href="#" wire:click.prevent="promptDeleteTeam('{{ $team->id }}')">
                                            <x-slot:icon><x-heroicon-o-trash aria-hidden="true" /></x-slot:icon>
                                            {{ __('Delete team') }}
                                        </x-dropdown-link>
                                    </x-slot:content>
                                </x-dropdown>
                            @endif
                        </div>
                    @empty
                        <p class="px-2 pb-1 text-xs leading-relaxed text-brand-mist">
                            {{ __('No teams yet. A team groups people so alerts reach the group that owns a server.') }}
                        </p>
                    @endforelse

                    @if ($isAdmin)
                        <button
                            type="button"
                            wire:click="openTeamModal"
                            class="mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-brand-sage transition-colors hover:bg-brand-sand/25 hover:text-brand-ink"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New team') }}
                        </button>
                    @endif
                </nav>

                <div class="min-w-0 flex-1">
                    {{-- Pending invitations. Surface only when there's something
                         pending — collapsing the section when empty keeps the
                         page focused on the actual member directory. --}}
                    @if ($visibleInvitations->isNotEmpty())
                        <section class="border-b border-brand-ink/10">
                            <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-amber-50/50 px-5 py-2 sm:px-6">
                                <x-heroicon-o-envelope class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Pending invitations') }}</h3>
                                <span class="text-xs text-brand-moss">{{ __('sent, not yet accepted') }}</span>
                                <span class="ms-auto shrink-0 rounded-md border border-amber-200 bg-white px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums text-amber-900">{{ $visibleInvitations->count() }}</span>
                            </div>
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($visibleInvitations as $inv)
                                    {{-- Same shape as a member row — avatar, name +
                                         detail, then teams and role right-aligned — so a
                                         pending invite reads as "this person, not yet
                                         here" instead of a differently-built strip. --}}
                                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-5 py-2 transition-colors hover:bg-brand-sand/15 sm:px-6">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                            <x-heroicon-o-envelope class="h-3.5 w-3.5" aria-hidden="true" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <span class="text-sm font-semibold text-brand-ink">{{ $inv->email }}</span>
                                            <span class="mt-0.5 block truncate text-xs text-brand-moss sm:mt-0 sm:ml-2 sm:inline">
                                                @if ($inv->expires_at)
                                                    {{ __('Invited · expires :time', ['time' => $inv->expires_at->diffForHumans()]) }}
                                                @else
                                                    {{ __('Invited · awaiting acceptance') }}
                                                @endif
                                            </span>
                                        </div>

                                        {{-- An invite sent with a team joins the org and that team in one accept. --}}
                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            @if ($inv->team)
                                                <span class="inline-flex items-center rounded-md border border-dashed border-brand-ink/20 bg-brand-sand/25 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss">{{ $inv->team->name }}</span>
                                            @endif
                                        </div>

                                        <span class="shrink-0 rounded-md border border-dashed px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($inv->role) }}">{{ $inv->role }}</span>

                                        @if ($isAdmin)
                                            <button
                                                type="button"
                                                wire:click="promptCancelInvitation('{{ $inv->id }}')"
                                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-2xs font-semibold text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
                                            >
                                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Cancel') }}
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    {{-- Member directory. No section header: the dense panel head
                         above already names the page and carries Invite. --}}
                    <section class="border-b border-brand-ink/10 last:border-b-0">
                        @if ($visibleMembers->isEmpty())
                            <div class="px-5 py-10 text-center sm:px-6">
                                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                                </span>
                                @if ($activeTeam)
                                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('Nobody on :team yet.', ['team' => $activeTeam->name]) }}</p>
                                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-mist">
                                        {{ __('Switch to All people and click + in someone\'s Teams column to add them, or invite a new address by email.') }}
                                    </p>
                                    <button type="button" wire:click="selectTeam" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Show all people') }} →</button>
                                @else
                                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No members yet.') }}</p>
                                    @if ($isAdmin)
                                        <button type="button" wire:click="openInviteModal" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Invite the first one') }} →</button>
                                    @endif
                                @endif
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($visibleMembers as $user)
                                    @php
                                        $role = strtolower((string) $user->pivot->role);
                                        $onTeams = $teams->filter(fn ($t) => $t->users->contains('id', $user->id));
                                        $offTeams = $teams->reject(fn ($t) => $t->users->contains('id', $user->id));
                                    @endphp
                                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-5 py-2 transition-colors hover:bg-brand-sand/15 sm:px-6">
                                        {{-- Avatar + name + email are one link to the
                                             member's profile; the chips beside them stay
                                             their own controls. --}}
                                        <a
                                            href="{{ route('organizations.member', [$organization, $user]) }}"
                                            wire:navigate
                                            class="group flex min-w-0 flex-1 items-center gap-3"
                                        >
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-2xs font-semibold ring-1 {{ $avatarClasses($role) }}">
                                                {{ $initialsOf($user) }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="text-sm font-semibold text-brand-ink group-hover:text-brand-sage group-hover:underline">{{ $user->name }}</span>
                                                <span class="mt-0.5 block truncate text-xs text-brand-moss sm:mt-0 sm:ml-2 sm:inline">{{ $user->email }}</span>
                                            </span>
                                        </a>

                                        {{-- Teams column. Each chip is the membership itself:
                                             click one to drop it (asks first — a chip is an
                                             easy mis-click), + to add. --}}
                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            @foreach ($onTeams as $team)
                                                @if ($isAdmin)
                                                    <button
                                                        type="button"
                                                        wire:click="promptRemoveFromTeam('{{ $team->id }}', '{{ $user->id }}')"
                                                        wire:loading.attr="disabled"
                                                        class="group inline-flex items-center gap-1 rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss transition-colors hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                        title="{{ __('Remove :name from :team', ['name' => $user->name, 'team' => $team->name]) }}"
                                                    >
                                                        {{ $team->name }}
                                                        <x-heroicon-o-x-mark class="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
                                                    </button>
                                                @else
                                                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss">{{ $team->name }}</span>
                                                @endif
                                            @endforeach

                                            @if ($isAdmin && $offTeams->isNotEmpty())
                                                <x-dropdown align="right" width="14rem">
                                                    <x-slot:trigger>
                                                        <button
                                                            type="button"
                                                            class="inline-flex h-5 items-center gap-1 rounded-md border border-dashed border-brand-ink/20 px-1.5 text-2xs font-semibold text-brand-mist transition-colors hover:border-brand-sage/50 hover:text-brand-ink"
                                                            aria-label="{{ __('Add :name to a team', ['name' => $user->name]) }}"
                                                        >
                                                            <x-heroicon-o-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                        </button>
                                                    </x-slot:trigger>

                                                    <x-slot:content>
                                                        <p class="px-3 pb-1.5 pt-1 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Add to team') }}</p>
                                                        @foreach ($offTeams as $team)
                                                            <x-dropdown-link href="#" wire:click.prevent="toggleTeamMembership('{{ $team->id }}', '{{ $user->id }}')">
                                                                <x-slot:icon><x-heroicon-o-rectangle-group aria-hidden="true" /></x-slot:icon>
                                                                {{ $team->name }}
                                                            </x-dropdown-link>
                                                        @endforeach
                                                    </x-slot:content>
                                                </x-dropdown>
                                            @endif
                                        </div>

                                        <span class="shrink-0 rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($user->pivot->role) }}">{{ $user->pivot->role }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>

            {{-- Footer: the questions this page raises next. --}}
            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Roles decide access. Teams don\'t — they scope which group hears an alert.') }}
                    </span>
                    <span class="text-brand-mist">{{ __('Invitations expire after 7 days. Owner can\'t be granted by invite.') }}</span>
                    @if ($activeTeam)
                        <a href="{{ route('teams.notification-channels', [$organization, $activeTeam]) }}" wire:navigate class="ms-auto font-semibold text-brand-sage hover:text-brand-ink">
                            {{ __(':team notification channels', ['team' => $activeTeam->name]) }} →
                        </a>
                    @endif
                </div>
            </x-slot:footer>
        </x-organization-shell>
    </div>

    @if ($isAdmin)
        <x-modal
            name="invite-member-modal"
            :show="$show_invite_modal"
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
                        <x-input-label for="invite_email_modal" :value="__('Email address or name')" />
                        <x-text-input
                            id="invite_email_modal"
                            wire:model.live.debounce.300ms="invite_email"
                            type="text"
                            class="mt-2 block w-full"
                            placeholder="{{ __('name@company.com') }}"
                            required
                            autocomplete="off"
                        />

                        {{-- Type-ahead over existing dply accounts (people you already
                             work with rank first), so a known user can be invited by
                             name. Anyone without an account: just type the email. --}}
                        @php($inviteMatches = $this->inviteSuggestions())
                        @if ($inviteMatches->isNotEmpty())
                            <ul class="mt-2 divide-y divide-brand-ink/5 overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                                @foreach ($inviteMatches as $candidate)
                                    <li wire:key="invite-match-{{ $candidate->id }}">
                                        <button
                                            type="button"
                                            wire:click="pickInviteSuggestion(@js($candidate->email))"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/40"
                                        >
                                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-moss/15 text-2xs font-semibold text-brand-moss">{{ $initialsOf($candidate) }}</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-brand-ink">{{ $candidate->name }}</span>
                                                <span class="block truncate text-xs text-brand-moss">{{ $candidate->email }}</span>
                                            </span>
                                            @if ($candidate->shares_org)
                                                <span class="shrink-0 rounded-md bg-brand-sand/55 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss">{{ __('You work together') }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

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
                    @if ($teams->isNotEmpty())
                        <div>
                            <x-input-label for="invite_team_modal" :value="__('Team (optional)')" />
                            <x-select id="invite_team_modal" wire:model="invite_team_id" class="mt-2">
                                <option value="">{{ __('No team') }}</option>
                                @foreach ($teams as $team)
                                    <option value="{{ $team->id }}">{{ $team->name }}</option>
                                @endforeach
                            </x-select>
                            <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Accepting joins the organization and this team at once.') }}</p>
                            <x-input-error :messages="$errors->get('invite_team_id')" class="mt-2" />
                        </div>
                    @endif
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

        {{-- One modal for both create and rename: the only field either needs
             is the name, so a second modal would be the same form twice. --}}
        <x-modal
            name="team-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="saveTeam">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $editingTeamId ? __('Rename team') : __('New team') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Name your team') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('A team groups people so alerts reach the group that owns a server. It grants no extra access.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="team_name_modal" :value="__('Team name')" />
                        <x-text-input
                            id="team_name_modal"
                            wire:model="team_name"
                            type="text"
                            class="mt-2 block w-full"
                            placeholder="{{ __('e.g. Platform, Customer success') }}"
                            required
                            maxlength="255"
                            autocomplete="off"
                        />
                        <x-input-error :messages="$errors->get('team_name')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeTeamModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveTeam">
                        <span wire:loading.remove wire:target="saveTeam" class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ $editingTeamId ? __('Save name') : __('Create team') }}
                        </span>
                        <span wire:loading wire:target="saveTeam" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Saving…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
