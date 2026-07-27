@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'forest' => 'bg-brand-forest/10 text-brand-forest ring-brand-forest/20',
    ];

    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $canViewCreds = auth()->user()?->can('viewAny', \App\Models\ProviderCredential::class) ?? false;
    $canViewChannels = auth()->user()?->can('viewNotificationChannels', $organization) ?? false;

    // Quick-link tiles. Built as data so the same shape can be rendered in
    // a tighter grid below, and each entry only emits when the viewer has
    // permission for that destination.
    $quickLinks = array_values(array_filter([
        [
            'label' => __('Members'),
            'description' => __('People, roles, and invitations.'),
            'href' => route('organizations.members', $organization),
            'icon' => 'heroicon-o-user-group',
            'tone' => 'sage',
            'show' => true,
        ],
        [
            'label' => __('Teams'),
            'description' => __('Group members for scoped notifications.'),
            'href' => route('organizations.teams', $organization),
            'icon' => 'heroicon-o-rectangle-group',
            'tone' => 'sand',
            'show' => true,
        ],
        [
            'label' => __('Activity'),
            'description' => __('Audit trail for everything that mutates.'),
            'href' => route('organizations.activity', $organization),
            'icon' => 'heroicon-o-archive-box',
            'tone' => 'violet',
            'show' => $isAdmin,
        ],
        [
            'label' => __('Automation & API'),
            'description' => __('API tokens and outbound webhooks.'),
            'href' => route('organizations.automation', $organization),
            'icon' => 'heroicon-o-bolt',
            'tone' => 'amber',
            'show' => $isAdmin,
        ],
        [
            'label' => __('Notification channels'),
            'description' => __('Slack, email, Pushover, webhooks.'),
            'href' => route('organizations.notification-channels', $organization),
            'icon' => 'heroicon-o-bell-alert',
            'tone' => 'sky',
            'show' => $canViewChannels,
        ],
        [
            'label' => __('Provider credentials'),
            'description' => __('Encrypted tokens for clouds, DNS, CDN.'),
            'href' => route('organizations.credentials', $organization),
            'icon' => 'heroicon-o-key',
            'tone' => 'sage',
            'show' => $canViewCreds,
        ],
        [
            'label' => __('Webserver templates'),
            'description' => __('Reusable nginx / Apache / Caddy snippets.'),
            'href' => route('organizations.webserver-templates', $organization),
            'icon' => 'heroicon-o-server',
            'tone' => 'forest',
            'show' => auth()->user()?->can('view', $organization) ?? false,
        ],
        [
            'label' => __('Billing & plan'),
            'description' => __('Usage, payment method, invoices.'),
            'href' => route('billing.show', $organization),
            'icon' => 'heroicon-o-credit-card',
            'tone' => 'sage',
            'show' => $isAdmin,
        ],
    ], fn (array $l) => $l['show']));
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="overview"
            :title="$organization->name"
            :description="__('Plan, people, and the surface for everything dply automates on your behalf — pick a section below.')"
            icon="heroicon-o-building-office-2"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]"
        >
            <x-slot:actions>
                <x-docs-link slug="org-overview" size="md">
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Organization guide') }}
                </x-docs-link>
                <x-docs-link slug="org-roles-and-limits" size="md">
                    <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Roles & limits') }}
                </x-docs-link>
                @if ($isAdmin)
                    <a href="{{ route('billing.show', $organization) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                        <x-heroicon-o-credit-card class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Billing & plan') }}
                    </a>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="{{ __('Organization at a glance') }}">
                    <x-fleet-stat :label="__('Plan')">
                        <p class="mt-2 truncate text-sm font-semibold text-brand-ink" title="{{ $organization->planTierLabel() }}">{{ $organization->planTierLabel() }}</p>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Org-wide subscription') }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Fleet')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $organization->servers_count }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('server|servers', $organization->servers_count) }}</span>
                        </p>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ $organization->sites_count }} {{ trans_choice('site|sites', $organization->sites_count) }}</p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('People')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $organization->users->count() }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('member|members', $organization->users->count()) }}</span>
                        </p>
                        <p class="mt-1 text-[11px] text-brand-mist">
                            {{ $organization->teams->count() }} {{ trans_choice('team|teams', $organization->teams->count()) }}
                            @if ($organization->invitations->count() > 0)
                                · {{ $organization->invitations->count() }} {{ trans_choice('pending|pending', $organization->invitations->count()) }}
                            @endif
                        </p>
                    </x-fleet-stat>
                    <x-fleet-stat :label="__('Automation')">
                        <p class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $organization->apiTokens->count() }}</span>
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('API token|API tokens', $organization->apiTokens->count()) }}</span>
                        </p>
                        <p class="mt-1 text-[11px] text-brand-mist">{{ $organization->notificationWebhookDestinations->count() }} {{ trans_choice('webhook|webhooks', $organization->notificationWebhookDestinations->count()) }}</p>
                    </x-fleet-stat>
                </dl>
            </x-slot:stats>

            {{-- Section navigator as a hairline strip (not a nested card). --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <x-icon-badge>
                        <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Navigate') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization sections') }}</h2>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Jump straight to the surface you need — billing, people, channels, credentials, and automation.') }}</p>
                    </div>
                </div>
                <ul class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($quickLinks as $link)
                        <li>
                            <a
                                href="{{ $link['href'] }}"
                                wire:navigate
                                class="group relative flex h-full w-full flex-col items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 text-left transition hover:border-brand-sage/35 hover:bg-brand-sand/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                            >
                                <div class="flex w-full items-start justify-between gap-2">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette[$link['tone']] }}">
                                        <x-dynamic-component :component="$link['icon']" class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <span aria-hidden="true" class="self-center text-brand-mist transition group-hover:translate-x-0.5 group-hover:text-brand-moss">→</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $link['label'] }}</p>
                                    <p class="mt-0.5 text-[11px] leading-relaxed text-brand-moss">{{ $link['description'] }}</p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </x-organization-shell>
    </div>
</div>
