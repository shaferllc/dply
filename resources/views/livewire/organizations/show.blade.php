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
        <x-organization-shell :organization="$organization" section="overview">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]" />

            {{-- Hero card. Stat strip on the right summarizes the four
                 numbers an admin scans for first — plan, fleet, people,
                 automation. Each tile keeps the family stat-tile look. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-building-office-2 class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Organization') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ $organization->name }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Plan, people, and the surface for everything dply automates on your behalf — pick a section below.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-docs-link doc-route="docs.markdown" doc-slug="org-roles-and-limits">
                                <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Roles & limits') }}
                            </x-docs-link>
                            <x-docs-link doc-route="docs.index">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </x-docs-link>
                            @if ($isAdmin)
                                <a href="{{ route('billing.show', $organization) }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest">
                                    <x-heroicon-o-credit-card class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Billing & plan') }}
                                </a>
                            @endif
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-2 lg:col-span-5">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Plan') }}</dt>
                            <dd class="mt-1 truncate text-sm font-semibold text-brand-ink" title="{{ $organization->planTierLabel() }}">{{ $organization->planTierLabel() }}</dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Org-wide subscription') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fleet') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $organization->servers_count }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('server|servers', $organization->servers_count) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ $organization->sites_count }} {{ trans_choice('site|sites', $organization->sites_count) }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('People') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $organization->users->count() }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('member|members', $organization->users->count()) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">
                                {{ $organization->teams->count() }} {{ trans_choice('team|teams', $organization->teams->count()) }}
                                @if ($organization->invitations->count() > 0)
                                    · {{ $organization->invitations->count() }} {{ trans_choice('pending|pending', $organization->invitations->count()) }}
                                @endif
                            </p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Automation') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $organization->apiTokens->count() }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('API token|API tokens', $organization->apiTokens->count()) }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ $organization->notificationWebhookDestinations->count() }} {{ trans_choice('webhook|webhooks', $organization->notificationWebhookDestinations->count()) }}</p>
                        </div>
                    </dl>
                </div>
            </section>

            {{-- Section navigator. Mirrors the credentials provider grid:
                 icon tile + label + one-line description on each card, all
                 clickable. Cleaner than the previous list of plain "→"
                 link rows. --}}
            <section class="dply-card mt-6 overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Navigate') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization sections') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Jump straight to the surface you need — billing, people, channels, credentials, and automation.') }}</p>
                    </div>
                </div>
                <ul class="grid gap-3 p-6 sm:p-7 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($quickLinks as $link)
                        <li>
                            <a
                                href="{{ $link['href'] }}"
                                wire:navigate
                                class="group relative flex h-full w-full flex-col items-start gap-3 rounded-2xl border border-brand-ink/10 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand-sage/35 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
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
