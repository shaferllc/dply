@php
    $canManageTeams = $currentOrg && $currentOrg->hasAdminAccess(auth()->user());
@endphp

<div
    class="min-w-0 max-w-full"
    x-data="{
        orgOpen: false,
        teamOpen: false,
        closeAll() { this.orgOpen = false; this.teamOpen = false; },
        toggleOrg() { this.teamOpen = false; this.orgOpen = !this.orgOpen; },
        toggleTeam() { this.orgOpen = false; this.teamOpen = !this.teamOpen; },
    }"
    @keydown.escape.window="closeAll()"
>
    <div class="min-w-0">
        {{-- overflow visible so org/team menus are not clipped below the header row --}}
        <nav class="flex flex-nowrap items-center gap-x-1 gap-y-1 overflow-visible" aria-label="{{ __('Workspace context') }}">
            <div class="relative flex flex-nowrap items-center gap-x-1.5 min-w-0">
                {{-- Organization switcher --}}
                <div class="relative min-w-0 shrink-0" @click.outside="orgOpen = false">
                    <button
                        type="button"
                        class="group flex max-w-[min(42vw,11rem)] sm:max-w-[13rem] lg:max-w-[15rem] items-center gap-2 rounded-lg border border-brand-ink/10 bg-white/90 px-2 py-1.5 text-left shadow-sm transition hover:border-brand-ink/20 hover:bg-white"
                        @click="toggleOrg()"
                        :aria-expanded="orgOpen.toString()"
                        aria-haspopup="listbox"
                    >
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand-sage text-[10px] font-bold text-white shadow-inner shadow-brand-forest/20" aria-hidden="true">
                            {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name ?? __('Org')) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Organization') }}</span>
                            <span class="block truncate text-xs font-semibold text-brand-ink">{{ $currentOrg->name ?? __('None') }}</span>
                        </span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-brand-moss group-hover:text-brand-ink" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                        </svg>
                    </button>

                    <div
                        x-cloak
                        x-show="orgOpen"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute left-0 z-50 mt-2 w-[min(100vw-2rem,20rem)] dply-dropdown-panel py-2"
                        role="listbox"
                    >
                        <p class="px-4 pb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Switch organization') }}</p>
                        <ul class="max-h-64 overflow-y-auto px-2">
                            @foreach ($organizations as $org)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="switchOrganization('{{ $org->id }}')"
                                        wire:loading.attr="disabled"
                                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                        role="option"
                                        @click="orgOpen = false"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage text-xs font-bold text-white">
                                            {{ \App\Livewire\Layout\ContextBreadcrumb::initials($org->name) }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-brand-ink">{{ $org->name }}</span>
                                            <span class="block text-xs text-brand-moss">{{ __('Organization') }}</span>
                                        </span>
                                        @if ($currentOrg && $currentOrg->is($org))
                                            <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                            </svg>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="my-2 border-t border-brand-ink/10"></div>
                        <div class="space-y-0.5 px-2">
                            @if ($currentOrg)
                                <a
                                    href="{{ route('organizations.show', $currentOrg) }}"
                                    wire:navigate
                                    class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                    @click="orgOpen = false"
                                >
                                    <svg class="h-4 w-4 shrink-0 text-brand-moss" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    {{ __('Organization settings') }}
                                </a>
                            @endif
                            <a
                                href="{{ route('organizations.index') }}"
                                wire:navigate
                                class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                @click="orgOpen = false"
                            >
                                <svg class="h-4 w-4 shrink-0 text-brand-moss" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.09 9.09 0 003.741-.479 3 3 0 004.006-4.006 9.09 9.09 0 001.05-.697m-6.41-12.12a9.09 9.09 0 00-3.741.479 3 3 0 00-4.006 4.006 9.09 9.09 0 00-1.05.697m6.41 12.12a9.09 9.09 0 01-3.741-.479 3 3 0 01-4.006-4.006 9.09 9.09 0 01-1.05-.697m6.41-12.12a9.09 9.09 0 013.741.479 3 3 0 014.006 4.006 9.09 9.09 0 011.05.697"/></svg>
                                {{ __('All organizations') }}
                            </a>
                            <a
                                href="{{ route('organizations.create') }}"
                                wire:navigate
                                class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                @click="orgOpen = false"
                            >
                                <svg class="h-4 w-4 shrink-0 text-brand-moss" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                {{ __('Create organization') }}
                            </a>
                        </div>
                    </div>
                </div>

                @if ($currentOrg)
                    <span class="text-brand-mist/80 select-none text-xs" aria-hidden="true">/</span>

                    {{-- Team switcher --}}
                    <div class="relative min-w-0 shrink-0" @click.outside="teamOpen = false">
                        @if ($teams->isEmpty())
                            <div class="flex max-w-[min(42vw,11rem)] items-center gap-2 rounded-lg border border-dashed border-brand-ink/15 bg-white/50 px-2 py-1.5 text-xs text-brand-moss">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand-sand/80 text-[10px] font-bold text-brand-moss">—</span>
                                <span class="min-w-0">
                                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Team') }}</span>
                                    <a href="{{ route('organizations.show', $currentOrg) }}" wire:navigate class="block truncate font-medium text-brand-sage hover:text-brand-ink">{{ __('No teams yet — set up on the org page') }}</a>
                                </span>
                            </div>
                        @else
                            <button
                                type="button"
                                class="group flex max-w-[min(42vw,11rem)] sm:max-w-[13rem] lg:max-w-[15rem] items-center gap-2 rounded-lg border border-brand-ink/10 bg-white/90 px-2 py-1.5 text-left shadow-sm transition hover:border-brand-ink/20 hover:bg-white"
                                @click="toggleTeam()"
                                :aria-expanded="teamOpen.toString()"
                                aria-haspopup="listbox"
                            >
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[#3b6fb6] text-[10px] font-bold text-white shadow-inner shadow-brand-ink/10" aria-hidden="true">
                                    @if ($currentTeam)
                                        {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentTeam->name) }}
                                    @else
                                        {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name) }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Team') }}</span>
                                    <span class="block truncate text-xs font-semibold text-brand-ink">
                                        {{ $currentTeam?->name ?? __('All teams') }}
                                    </span>
                                </span>
                                <svg class="h-3.5 w-3.5 shrink-0 text-brand-moss group-hover:text-brand-ink" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                </svg>
                            </button>

                            <div
                                x-cloak
                                x-show="teamOpen"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="absolute left-0 z-50 mt-2 w-[min(100vw-2rem,20rem)] dply-dropdown-panel py-2 sm:left-auto sm:right-0"
                                role="listbox"
                            >
                                <p class="px-4 pb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-moss">{{ __('Switch team') }}</p>
                                <ul class="max-h-64 overflow-y-auto px-2">
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="switchTeam"
                                            wire:loading.attr="disabled"
                                            class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                            role="option"
                                            @click="teamOpen = false"
                                        >
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/80 text-xs font-bold text-brand-moss">
                                                {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name) }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium text-brand-ink">{{ __('All teams') }}</span>
                                                <span class="block text-xs text-brand-moss">{{ __('Whole organization') }}</span>
                                            </span>
                                            @if (! $currentTeam)
                                                <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            @endif
                                        </button>
                                    </li>
                                    @foreach ($teams as $team)
                                        <li>
                                            <button
                                                type="button"
                                                wire:click="switchTeam('{{ $team->id }}')"
                                                wire:loading.attr="disabled"
                                                class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                                role="option"
                                                @click="teamOpen = false"
                                            >
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#3b6fb6] text-xs font-bold text-white">
                                                    {{ \App\Livewire\Layout\ContextBreadcrumb::initials($team->name) }}
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate font-medium text-brand-ink">{{ $team->name }}</span>
                                                    <span class="block text-xs text-brand-moss">{{ __('Team') }}</span>
                                                </span>
                                                @if ($currentTeam && $currentTeam->is($team))
                                                    <svg class="h-5 w-5 shrink-0 text-brand-sage" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                    </svg>
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="my-2 border-t border-brand-ink/10"></div>
                                <div class="space-y-0.5 px-2">
                                    <a
                                        href="{{ route('organizations.show', $currentOrg) }}"
                                        wire:navigate
                                        class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                        @click="teamOpen = false"
                                    >
                                        <svg class="h-4 w-4 shrink-0 text-brand-moss" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.09 9.09 0 003.741-.479 3 3 0 004.006-4.006 9.09 9.09 0 001.05-.697m-6.41-12.12a9.09 9.09 0 00-3.741.479 3 3 0 00-4.006 4.006 9.09 9.09 0 00-1.05.697m6.41 12.12a9.09 9.09 0 01-3.741-.479 3 3 0 01-4.006-4.006 9.09 9.09 0 01-1.05-.697m6.41-12.12a9.09 9.09 0 013.741.479 3 3 0 014.006 4.006 9.09 9.09 0 011.05.697"/></svg>
                                        {{ __('Teams on org page') }}
                                    </a>
                                    @if ($canManageTeams)
                                        <a
                                            href="{{ route('organizations.show', $currentOrg) }}"
                                            wire:navigate
                                            class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                            @click="teamOpen = false"
                                        >
                                            <svg class="h-4 w-4 shrink-0 text-brand-moss" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                            {{ __('Create team') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </nav>
    </div>
</div>
