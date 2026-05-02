<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Dashboard') }}</a></li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Backups') }}</li>
                <li class="text-brand-mist" aria-hidden="true">/</li>
                <li class="text-brand-ink font-medium">{{ __('Files') }}</li>
            </ol>
        </nav>

        <x-page-header
            :title="__('File backups')"
            :description="__('Protect uploads, shared assets, and app-specific paths for :org with a recovery policy your team can explain. Queue a full archive (tar.gz) of each site’s repository root from here, with standard excludes such as vendor and node_modules—pair that with database exports on the Databases tab for a complete restore story.', ['org' => $organization->name])"
            doc-route="docs.index"
            flush
            compact
        />

        <x-backups-subnav active="files" />

        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scope') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Decide what matters') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Include the paths that are hard to recreate, such as uploads, user-generated assets, shared config, and release artifacts you cannot rebuild quickly.') }}
                </p>
            </section>
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Noise control') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Record excludes and limits') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Temporary caches, vendor trees, or build output often belong in deploys, not in archives. Capture exclusions and bandwidth constraints before they surprise you.') }}
                </p>
            </section>
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Recovery drill') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Practice the import path') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('The right backup policy includes a destination, a restore location, and a documented drill for replacing content on a new server or after a bad release.') }}
                </p>
            </section>
        </div>

        <div class="mb-6 rounded-2xl border border-brand-gold/35 bg-brand-sand/50 px-5 py-4 shadow-sm">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Good file backup hygiene') }}</p>
            <ul class="mt-2 space-y-1 text-sm leading-relaxed text-brand-moss list-disc list-inside">
                <li>{{ __('List the paths you would miss in the first hour of an outage.') }}</li>
                <li>{{ __('Keep exclusions explicit so archives stay small and predictable.') }}</li>
                <li>{{ __('Store the restore destination and verification step with the same site.') }}</li>
            </ul>
        </div>

        <div class="grid gap-4 lg:grid-cols-3 mb-6">
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Available storage destinations') }}</h2>
                @if ($storageDestinations->isEmpty())
                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                        {{ __('No storage destinations yet. Add one before you expect repeatable file recovery.') }}
                    </p>
                    <a href="{{ route('profile.backup-configurations') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-sage hover:text-brand-ink">
                        {{ __('Add storage destination') }}
                    </a>
                @else
                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                        {{ trans_choice(':count destination can be reused for archives and restore drills.|:count destinations can be reused for archives and restore drills.', $storageDestinations->count(), ['count' => $storageDestinations->count()]) }}
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($storageDestinations->take(4) as $destination)
                            <span class="inline-flex items-center gap-2 rounded-full border border-brand-ink/10 bg-brand-sand/30 px-3 py-1 text-xs text-brand-ink">
                                <span class="font-semibold">{{ $destination->name }}</span>
                                <span class="text-brand-mist">· {{ $providerLabels[$destination->provider] ?? $destination->provider }}</span>
                            </span>
                        @endforeach
                        @if ($storageDestinations->count() > 4)
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-xs text-brand-moss">
                                {{ __('+:count more', ['count' => $storageDestinations->count() - 4]) }}
                            </span>
                        @endif
                    </div>
                @endif
            </section>
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Recovery drill') }}</h2>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-brand-moss list-disc list-inside">
                    <li>{{ __('Confirm the writable paths that matter for each site.') }}</li>
                    <li>{{ __('Write down where restored files should land.') }}</li>
                    <li>{{ __('Keep a runbook for post-restore checks and cache clears.') }}</li>
                </ul>
            </section>
        </div>

        <div class="dply-card overflow-hidden">
            <div class="px-4 py-3 sm:px-6 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Sites in this organization') }}</h2>
                    <p class="text-xs text-brand-moss mt-0.5">{{ __('Use each site as the source of truth for what should be archived, excluded, and restored.') }}</p>
            </div>
            @if ($sites->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm text-brand-moss">{{ __('No sites yet. Create a server and add a site to enable file backups.') }}</p>
                    <div class="mt-4 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('launches.create') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Open launchpad') }}</a>
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
                                <th class="px-4 py-3">{{ __('Archive scope') }}</th>
                                <th class="px-4 py-3 min-w-[14rem]">{{ __('Full backup') }}</th>
                                <th class="px-4 py-3">{{ __('Recovery note') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10">
                            @foreach ($sites as $site)
                                @php
                                    $runbookCount = $site->workspace?->runbooks?->count() ?? 0;
                                    $effectiveRoot = $site->effectiveRepositoryPath();
                                    $siteBackups = $recentBackups->get($site->id) ?? collect();
                                @endphp
                                <tr wire:key="file-backup-{{ $site->id }}" class="hover:bg-brand-sand/20">
                                    <td class="px-4 py-3 font-medium text-brand-ink">
                                        <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-sage">{{ $site->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss">{{ $site->server?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-brand-moss">
                                        <div class="space-y-1">
                                            <p>{{ __('Document root: :path', ['path' => $site->document_root]) }}</p>
                                            <p class="text-xs text-brand-mist">
                                                @if ($effectiveRoot !== $site->document_root)
                                                    {{ __('Repository root: :path. Add excludes for caches, vendor trees, and other deploy-generated content.', ['path' => $effectiveRoot]) }}
                                                @else
                                                    {{ __('Add excludes for caches, vendor trees, and other deploy-generated content.') }}
                                            @endif
                                        </p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss align-top">
                                        <div class="space-y-2">
                                            @if ($site->supportsSshFileArchive())
                                                <button
                                                    type="button"
                                                    wire:click="queueFullBackup('{{ $site->id }}')"
                                                    class="inline-flex items-center rounded-lg bg-brand-sage px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-sage/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                                                >
                                                    {{ __('Queue full backup') }}
                                                </button>
                                                <p class="text-xs text-brand-mist">{{ __('Full snapshot of the repository root (compressed). Vendor, node_modules, .git, and similar paths are excluded by default.') }}</p>
                                            @else
                                                <p class="text-sm">{{ __('Full file backup requires an SSH-ready VM site.') }}</p>
                                            @endif
                                            @if ($siteBackups->isNotEmpty())
                                                <ul class="mt-2 space-y-1 text-xs">
                                                    @foreach ($siteBackups as $b)
                                                        <li wire:key="site-file-bu-{{ $b->id }}" class="flex flex-wrap items-center gap-2">
                                                            <span class="text-brand-mist">{{ $b->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                                                            <span class="font-medium text-brand-ink">{{ str($b->status)->replace('_', ' ')->title() }}</span>
                                                            @if ($b->status === \App\Models\SiteFileBackup::STATUS_COMPLETED && $b->disk_path)
                                                                <button type="button" wire:click="downloadSiteFileBackup('{{ $b->id }}')" class="text-brand-sage hover:text-brand-ink font-medium">
                                                                    {{ __('Download') }}
                                                                </button>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss">
                                        <div class="space-y-1">
                                            @if ($runbookCount > 0)
                                                <p>{{ trans_choice(':count project runbook is already attached to this site workspace.|:count project runbooks are already attached to this site workspace.', $runbookCount, ['count' => $runbookCount]) }}</p>
                                                <p class="text-xs text-brand-mist">{{ __('Use those runbooks to capture restore destination, cache-clear steps, and verification checks.') }}</p>
                                            @else
                                                <p>{{ __('No project runbook yet. Note where restored files should land and how you confirm the app is healthy afterward.') }}</p>
                                                <p class="text-xs text-brand-mist">{{ __('Project delivery and runbooks are the right home for step-by-step recovery notes.') }}</p>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-6 rounded-2xl border border-brand-ink/10 bg-white px-5 py-4 shadow-sm">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Database plus files') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-brand-moss max-w-3xl">
                {{ __('Database exports remain full logical dumps from the server workspace. File backups here are full tar.gz archives of the site repository root with shared excludes. There is no single combined artifact—restore SQL and files as two steps using your runbooks.') }}
            </p>
        </div>
    </div>
</div>
