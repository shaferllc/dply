<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Backups') }}</li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Databases') }}</li>
            </ol>
        </nav>

        <header class="mb-6">
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Database backups') }}</h1>
            <p class="mt-2 text-sm text-brand-moss max-w-3xl leading-relaxed">
                {{ __('Schedule and monitor database dumps for sites in :org. Connect storage under Storage destinations, then attach schedules per site when the backup runner is available on your servers.', ['org' => $organization->name]) }}
            </p>
        </header>

        <x-backups-subnav active="databases" />

        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-4 py-3 sm:px-6 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Sites in this organization') }}</h2>
                <p class="text-xs text-brand-moss mt-0.5">{{ __('Database backup jobs will target each site’s primary database connection.') }}</p>
            </div>
            @if ($sites->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm text-brand-moss">{{ __('No sites yet. Create a server and add a site to enable database backups.') }}</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('servers.create') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Create server') }}</a>
                        <span class="text-brand-mist" aria-hidden="true">·</span>
                        <a href="{{ route('sites.index') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('View sites') }}</a>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                <th class="px-4 py-3">{{ __('Site') }}</th>
                                <th class="px-4 py-3">{{ __('Server') }}</th>
                                <th class="px-4 py-3">{{ __('Schedule') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('Last backup') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10">
                            @foreach ($sites as $site)
                                <tr wire:key="db-backup-{{ $site->id }}" class="hover:bg-brand-sand/20">
                                    <td class="px-4 py-3 font-medium text-brand-ink">
                                        <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-sage">{{ $site->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss">{{ $site->server?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-brand-moss">{{ __('Not configured') }}</td>
                                    <td class="px-4 py-3 text-right text-brand-mist">—</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <p class="mt-6 text-xs text-brand-mist max-w-3xl leading-relaxed">
            {{ __('Automated dumps and uploads require the BYO agent on the target server. Until then, use Storage destinations for S3-compatible or other targets and run scripts on the server.') }}
        </p>
    </div>
</div>
