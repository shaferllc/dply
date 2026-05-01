<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="overview">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Organization overview') }}</p>
                            <h2 class="mt-2 text-lg font-semibold text-brand-ink">{{ $organization->name }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Plan usage, people, and quick links. Manage members, teams, billing, and automation from the sidebar or the shortcuts below.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                            <x-outline-link href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate>
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Roles & limits') }}
                            </x-outline-link>
                            <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </x-outline-link>
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <a href="{{ route('billing.show', $organization) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors">{{ __('Billing & plan') }}</a>
                            @endif
                        </div>
                    </div>
                </div>

                <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <x-stat-card
                        :label="__('Plan')"
                        :value="$organization->planTierLabel()"
                        :meta="__('Trial limits and billing apply to the whole organization.')"
                    />
                    <x-stat-card :label="__('Infrastructure')" :value="$organization->servers_count.' '.Str::plural('server', $organization->servers_count)">
                        <span class="text-sm text-brand-moss">
                            {{ $organization->sites_count }} {{ Str::plural('site', $organization->sites_count) }}
                            @if ($organization->maxServers() < PHP_INT_MAX || $organization->maxSites() < PHP_INT_MAX)
                                · {{ __('tracked against trial or plan limits') }}
                            @endif
                        </span>
                    </x-stat-card>
                    <x-stat-card
                        :label="__('People')"
                        :value="$organization->users->count().' '.Str::plural('member', $organization->users->count())"
                        :meta="$organization->teams->count().' '.Str::plural('team', $organization->teams->count()).' · '.$organization->invitations->count().' '.Str::plural('pending invite', $organization->invitations->count())"
                    />
                    <x-stat-card
                        :label="__('Automation')"
                        :value="$organization->apiTokens->count().' '.Str::plural('API token', $organization->apiTokens->count())"
                        :meta="$organization->notificationWebhookDestinations->count().' '.Str::plural('webhook destination', $organization->notificationWebhookDestinations->count())"
                    />
                </section>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Quick links') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Jump to members, teams, billing, and integrations.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-2">
                            <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                <span>{{ __('Members') }}</span>
                                <span aria-hidden="true" class="text-brand-moss">→</span>
                            </a>
                            <a href="{{ route('organizations.teams', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                <span>{{ __('Teams') }}</span>
                                <span aria-hidden="true" class="text-brand-moss">→</span>
                            </a>
                            @if ($organization->hasAdminAccess(auth()->user()))
                                <a href="{{ route('organizations.activity', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Activity') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                                <a href="{{ route('organizations.automation', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Automation & API') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                                <a href="{{ route('billing.show', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Billing & plan') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                            @endif
                            @can('viewNotificationChannels', $organization)
                                <a href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Notification channels') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                            @endcan
                            @can('viewAny', \App\Models\ProviderCredential::class)
                                <a href="{{ route('organizations.credentials', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Server providers') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                            @endcan
                            @can('view', $organization)
                                <a href="{{ route('organizations.webserver-templates', $organization) }}" wire:navigate class="flex items-center justify-between rounded-xl border border-brand-mist bg-white px-4 py-3 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/30">
                                    <span>{{ __('Webserver templates') }}</span>
                                    <span aria-hidden="true" class="text-brand-moss">→</span>
                                </a>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>
</div>
