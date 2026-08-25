@php
    $u = auth()->user();
    $isProfile = $section !== 'servers';
    $themeOptions = config('user_preferences.theme_options', []);
    $navLayoutOptions = config('user_preferences.navigation_layout_options', []);
    $countries = collect(config('profile_options.countries', []))->sort();
    $locales = config('profile_options.locales', []);
    $sessions = $this->sessions;
    $otherSessions = count(array_filter($sessions, fn ($s) => ! $s['is_current']));

    // Active values surfaced as stat tiles so the user can see at a glance
    // what they're currently set to without scrolling each form section.
    $currentTheme = $ui['theme'] ?? 'system';
    $currentNavLayout = $ui['navigation_layout'] ?? 'sidebar';
@endphp

<div
    x-data="{
        profileSaved: false,
        sessionRevoked: false,
        sessionsRevoked: false,
        init() {
            $wire.on('profile-updated', () => { this.profileSaved = true; setTimeout(() => { this.profileSaved = false }, 2000); });
            $wire.on('session-revoked', () => { this.sessionRevoked = true; setTimeout(() => { this.sessionRevoked = false }, 3000); });
            $wire.on('sessions-revoked', () => { this.sessionsRevoked = true; setTimeout(() => { this.sessionsRevoked = false }, 3000); });
        },
    }"
>
    @push('breadcrumbs')
        <x-breadcrumb-trail
            doc-route="docs.index"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
                ['label' => $isProfile ? __('Profile') : __('Servers & sites'), 'icon' => $isProfile ? 'user-circle' : 'server'],
            ]"
        />
    @endpush

    <x-profile-shell
        dense
        :title="$isProfile ? __('Profile') : __('Servers & sites')"
        :description="$isProfile
            ? __('Identity, preferences, sessions, and account on this page.')
            : __('Organization and team defaults for servers and sites.')"
        :icon="$isProfile ? 'heroicon-o-user-circle' : 'heroicon-o-server'"
    >
        {{-- No header actions: Security is one click away in the settings nav. --}}

        @if ($isProfile)
        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Your settings at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Theme') }}</dt>
                    <dd class="mt-0.5 flex items-center gap-1.5">
                        @if ($currentTheme === 'light')
                            <x-heroicon-m-sun class="h-3.5 w-3.5 shrink-0 text-amber-500" aria-hidden="true" />
                        @elseif ($currentTheme === 'dark')
                            <x-heroicon-m-moon class="h-3.5 w-3.5 shrink-0 text-brand-forest" aria-hidden="true" />
                        @else
                            <x-heroicon-m-computer-desktop class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                        @endif
                        <span class="truncate text-sm font-semibold capitalize text-brand-ink">{{ __(ucfirst((string) $currentTheme)) }}</span>
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Nav') }}</dt>
                    <dd class="mt-0.5 flex items-center gap-1.5">
                        @if ($currentNavLayout === 'top')
                            <x-heroicon-m-bars-3 class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ __('Top') }}</span>
                        @else
                            <x-heroicon-m-squares-2x2 class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ __('Sidebar') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Timezone') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="truncate text-sm font-semibold text-brand-ink" title="{{ $u?->timezone ?? config('app.timezone') }}">{{ $u?->timezone ?? config('app.timezone') }}</span>
                        <span class="shrink-0 font-mono text-xs tabular-nums text-brand-moss">{{ now($u?->timezone ?? config('app.timezone'))->format('g:i A') }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>
        @endif


        @if ($section === 'profile')
            @include($profileBodyPartial)
        @endif

        @if ($section === 'servers')
            @include('livewire.settings.partials.hub.servers')
        @endif
    </x-profile-shell>

    {{-- Included directly, not via a "modals" layout slot: this page's root is
         a plain <div>, so Blade has no component to bind the slot to and drops
         it — the confirm dialog never renders and the destructive action
         silently does nothing. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
