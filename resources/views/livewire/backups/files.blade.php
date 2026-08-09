<div class="contents">
    @if ($qdId)
        <div wire:poll.1500ms="pollQuickDownload" class="hidden"></div>
    @endif
    @if ($stagingId !== null)
        <div wire:poll.2s="pollStaging" class="hidden" aria-hidden="true"></div>
    @endif

    <x-compute-index-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Backups'), 'href' => route('backups.databases'), 'icon' => 'archive-box'],
            ['label' => __('Files')],
        ]" />

        <x-profile-shell
            dense
            :title="__('File backups')"
            :description="__('Archive site repository roots for :org — pair with database exports for a full restore.', ['org' => $organization->name])"
            icon="heroicon-o-folder"
        >
            <x-slot:actions>
                <a
                    href="{{ route('profile.backup-configurations') }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-archive-box class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Destinations') }}
                </a>
            </x-slot:actions>

            <x-slot:tabs>
                <x-backups-subnav active="files" />
            </x-slot:tabs>

            {{-- Compact guidance + destination chips --}}
            <section class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
                <p class="text-[11px] leading-relaxed text-brand-moss">
                    <span class="font-semibold text-brand-ink">{{ __('Hygiene:') }}</span>
                    {{ __('Back up hard-to-recreate paths, keep excludes explicit, and document restore destination + checks in a runbook.') }}
                </p>
                @if ($storageDestinations->isEmpty())
                    <p class="mt-1.5 text-[11px] text-brand-moss">
                        <a href="{{ route('profile.backup-configurations') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Add a storage destination') }}</a>
                        {{ __('before expecting repeatable recovery.') }}
                    </p>
                @else
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($storageDestinations->take(6) as $destination)
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 text-[10px] text-brand-ink">
                                <span class="font-semibold">{{ $destination->name }}</span>
                                <span class="text-brand-mist">· {{ $providerLabels[$destination->provider] ?? $destination->provider }}</span>
                            </span>
                        @endforeach
                        @if ($storageDestinations->count() > 6)
                            <span class="inline-flex items-center rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-[10px] text-brand-moss">
                                {{ __('+:count more', ['count' => $storageDestinations->count() - 6]) }}
                            </span>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Sites table --}}
            <section>
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-globe-alt"
                    :title="__('Sites in this organization')"
                    :count="$sites->count()"
                    :note="__('Queue a full tar.gz of each site repository root (vendor / node_modules excluded by default).')"
                />

                @if ($sites->isEmpty())
                    <div class="px-3 py-6 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No sites yet. Create a server and add a site to enable file backups.') }}</p>
                        <div class="mt-3 flex flex-wrap justify-center gap-3 text-xs font-semibold">
                            @if (multi_surface_active())
                                <a href="{{ route('launches.create') }}" wire:navigate class="text-brand-sage hover:text-brand-ink">{{ __('Open launchpad') }}</a>
                            @else
                                <a href="{{ route('servers.create') }}" wire:navigate class="text-brand-sage hover:text-brand-ink">{{ __('Add a server') }}</a>
                            @endif
                            <span class="text-brand-mist" aria-hidden="true">·</span>
                            <a href="{{ route('sites.index') }}" wire:navigate class="text-brand-sage hover:text-brand-ink">{{ __('View sites') }}</a>
                        </div>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-brand-ink/10 bg-brand-sand/15 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                                    <th class="px-3 py-2 sm:px-4">{{ __('Site') }}</th>
                                    <th class="px-3 py-2">{{ __('Server') }}</th>
                                    <th class="px-3 py-2">{{ __('Archive scope') }}</th>
                                    <th class="px-3 py-2 min-w-[12rem]">{{ __('Full backup') }}</th>
                                    <th class="px-3 py-2">{{ __('Recovery') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/8">
                                @foreach ($sites as $site)
                                    @php
                                        $runbookCount = $site->workspace?->runbooks?->count() ?? 0;
                                        $effectiveRoot = $site->effectiveRepositoryPath();
                                        $siteBackups = $recentBackups->get($site->id) ?? collect();
                                    @endphp
                                    <tr wire:key="file-backup-{{ $site->id }}" class="hover:bg-brand-sand/10">
                                        <td class="px-3 py-2 sm:px-4 font-medium text-brand-ink">
                                            <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-sage">{{ $site->name }}</a>
                                        </td>
                                        <td class="px-3 py-2 text-xs text-brand-moss">{{ $site->server?->name ?? '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-brand-moss">
                                            <p>{{ __('Document root: :path', ['path' => $site->document_root]) }}</p>
                                            @if ($effectiveRoot !== $site->document_root)
                                                <p class="text-[11px] text-brand-mist">{{ __('Repository root: :path', ['path' => $effectiveRoot]) }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-xs text-brand-moss align-top">
                                            <div class="space-y-1.5">
                                                @if ($site->supportsSshFileArchive())
                                                    <button
                                                        type="button"
                                                        wire:click="queueFullBackup('{{ $site->id }}')"
                                                        class="inline-flex h-6 items-center rounded-md bg-brand-ink px-2 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                                                    >
                                                        {{ __('Queue full backup') }}
                                                    </button>
                                                    <div>
                                                        <x-quick-download.site-menu :server="$site->server" :site="$site" :active-key="$qdTargetKey" />
                                                    </div>
                                                @else
                                                    <p>{{ __('Requires an SSH-ready VM site.') }}</p>
                                                @endif
                                                @if ($siteBackups->isNotEmpty())
                                                    <ul class="space-y-1">
                                                        @foreach ($siteBackups as $b)
                                                            <li wire:key="site-file-bu-{{ $b->id }}" class="flex flex-wrap items-center gap-2">
                                                                <span class="text-brand-mist">{{ $b->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                                                                <span class="font-medium text-brand-ink">{{ str($b->status)->replace('_', ' ')->title() }}</span>
                                                                @if ($b->isDownloadable())
                                                                    @if ($stagingBackupId === $b->id)
                                                                        <span class="font-medium text-brand-mist">{{ __('Preparing…') }}</span>
                                                                    @else
                                                                        <button type="button" wire:click="requestDownload('site_files', '{{ $b->id }}')" wire:loading.attr="disabled" wire:target="requestDownload" class="font-semibold text-brand-sage hover:text-brand-ink">
                                                                            {{ __('Download') }}
                                                                        </button>
                                                                    @endif
                                                                @endif
                                                                @if (isset($stagingErrors[$b->id]))
                                                                    <span class="w-full text-rose-700">{{ $stagingErrors[$b->id] }}</span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-xs text-brand-moss">
                                            @if ($runbookCount > 0)
                                                <p>{{ trans_choice(':count project runbook is already attached to this site workspace.|:count project runbooks are already attached to this site workspace.', $runbookCount, ['count' => $runbookCount]) }}</p>
                                            @else
                                                <p>{{ __('No project runbook yet. Note where restored files should land and how you confirm the app is healthy afterward.') }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-[11px] text-brand-moss sm:px-4">
                {{ __('Database dumps and file archives are separate artifacts — restore SQL, then files, using your runbooks.') }}
            </div>
        </x-profile-shell>
    </div>
</div>
