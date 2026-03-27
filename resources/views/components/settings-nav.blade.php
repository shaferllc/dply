@php
    $currentOrg = auth()->user()?->currentOrganization();
@endphp
<nav class="space-y-1 text-sm" aria-label="Account settings">
    <a
        href="{{ route('settings.index') }}"
        @class([
            'block rounded-lg px-3 py-2 font-medium transition-colors',
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('settings.index'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('settings.index'),
        ])
    >Overview</a>
    <a
        href="{{ route('profile.edit') }}"
        @class([
            'block rounded-lg px-3 py-2 font-medium transition-colors',
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('profile.edit'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('profile.edit'),
        ])
    >Profile</a>
    <a
        href="{{ route('two-factor.setup') }}"
        @class([
            'block rounded-lg px-3 py-2 font-medium transition-colors',
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('two-factor.setup'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('two-factor.setup'),
        ])
    >Two-factor authentication</a>
    <a
        href="{{ route('organizations.index') }}"
        @class([
            'block rounded-lg px-3 py-2 font-medium transition-colors',
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('organizations.index', 'organizations.create'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('organizations.index', 'organizations.create'),
        ])
    >Organizations</a>
    @if ($currentOrg)
        <p class="pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">Current organization</p>
        <a
            href="{{ route('organizations.show', $currentOrg) }}"
            @class([
                'block rounded-lg px-3 py-2 font-medium transition-colors',
                'bg-brand-sand/60 text-brand-ink' => request()->routeIs('organizations.show') && optional(request()->route('organization'))?->is($currentOrg),
                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! (request()->routeIs('organizations.show') && optional(request()->route('organization'))?->is($currentOrg)),
            ])
        >{{ $currentOrg->name }}</a>
        @if ($currentOrg->hasAdminAccess(auth()->user()))
            <a
                href="{{ route('billing.show', $currentOrg) }}"
                @class([
                    'block rounded-lg px-3 py-2 font-medium transition-colors',
                    'bg-brand-sand/60 text-brand-ink' => request()->routeIs('billing.show'),
                    'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('billing.show'),
                ])
            >Billing &amp; invoices</a>
        @endif
        <a
            href="{{ route('credentials.index') }}"
            @class([
                'block rounded-lg px-3 py-2 font-medium transition-colors',
                'bg-brand-sand/60 text-brand-ink' => request()->routeIs('credentials.*'),
                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('credentials.*'),
            ])
        >Provider credentials</a>
    @endif
    <p class="pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-brand-mist">Guides</p>
    <a
        href="{{ route('docs.source-control') }}"
        @class([
            'block rounded-lg px-3 py-2 font-medium transition-colors',
            'bg-brand-sand/60 text-brand-ink' => request()->routeIs('docs.source-control'),
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => ! request()->routeIs('docs.source-control'),
        ])
    >Source control &amp; deploys</a>
    <a
        href="{{ route('docs.index') }}"
        class="block rounded-lg px-3 py-2 font-medium text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink transition-colors"
    >All docs</a>
</nav>
