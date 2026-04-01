@php
    $currentOrg = auth()->user()?->currentOrganization();
    $link = 'flex items-center gap-2.5 rounded-lg px-3 py-2 font-medium transition-colors';
@endphp
<nav class="space-y-1 text-sm" aria-label="Account settings">
    <a
        href="{{ route('settings.index') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('settings.index'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('settings.index'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
        <span>{{ __('Preferences') }}</span>
    </a>
    <a
        href="{{ route('profile.edit') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.edit', 'profile.delete-account'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.edit', 'profile.delete-account'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
        <span>{{ __('Profile') }}</span>
    </a>
    <a
        href="{{ route('profile.referrals') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.referrals'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.referrals'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
        <span>{{ __('Referrals') }}</span>
    </a>
    <a
        href="{{ route('profile.security') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.security', 'two-factor.setup'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.security', 'two-factor.setup'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
        <span>{{ __('Security') }}</span>
    </a>
    <a
        href="{{ route('profile.source-control') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.source-control'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.source-control'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
        <span>{{ __('Source control') }}</span>
    </a>
    <a
        href="{{ route('profile.ssh-keys') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.ssh-keys'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.ssh-keys'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
        <span>{{ __('SSH keys') }}</span>
    </a>
    <a
        href="{{ route('profile.api-keys') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.api-keys'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.api-keys'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z"/></svg>
        <span>{{ __('API keys') }}</span>
    </a>
    <a
        href="{{ route('profile.backup-configurations') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.backup-configurations'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.backup-configurations'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
        <span>{{ __('Backup configurations') }}</span>
    </a>
    <a
        href="{{ route('profile.notification-channels') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.notification-channels'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.notification-channels'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.082A2.02 2.02 0 0021 14.018V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.018a2.02 2.02 0 001.143 1.964 23.848 23.848 0 005.454 1.082C12.84 18.73 14.458 20 16 20s3.16-1.27 3.857-2.918zM9.05 20.5a.75.75 0 01-.5-.866A14.15 14.15 0 019 18c0-1.098.272-2.113.75-3.05a.75.75 0 111.386.58A12.615 12.615 0 0010.5 18c0 .898.23 1.742.64 2.494a.75.75 0 01-.09 1.006z"/></svg>
        <span>{{ __('Notification channels') }}</span>
    </a>
    <a
        href="{{ route('organizations.index') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('organizations.index', 'organizations.create'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('organizations.index', 'organizations.create'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008H17.25v-.008zm0 3h.008v.008H17.25v-.008zm0 3h.008v.008H17.25v-.008z"/></svg>
        <span>{{ __('Organizations') }}</span>
    </a>
    <p class="flex items-center gap-2 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">
        <svg class="h-3.5 w-3.5 shrink-0 opacity-80" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
        <span>{{ __('Guides') }}</span>
    </p>
    <a
        href="{{ route('docs.source-control') }}"
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('docs.source-control'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('docs.source-control'),
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        <span>{{ __('Source control & deploys') }}</span>
    </a>
    <a
        href="{{ route('docs.index') }}"
        @class([
            $link,
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
        ])
    >
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
        <span>{{ __('All docs') }}</span>
    </a>
</nav>
