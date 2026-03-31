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
                {{ __('Define how production data for :org should be captured, retained, and restored. Storage destinations give every site a recovery target, while the backup plan here keeps schedules, ownership, and restore expectations visible to the whole team.', ['org' => $organization->name]) }}
            </p>
        </header>

        <x-backups-subnav active="databases" />

        <div class="grid gap-4 md:grid-cols-3 mb-6">
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('1. Schedule') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Choose a predictable dump window') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Set clear expectations for when database snapshots run, who owns them, and how much retention each site needs before traffic grows.') }}
                </p>
            </section>
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2. Verify') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Review where recovery confidence comes from') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Backups are only useful when operators agree on the destination, retention window, and current restore notes that explain how recovery should work.') }}
                </p>
            </section>
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('3. Restore') }}</p>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">{{ __('Keep recovery steps beside the app') }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                    {{ __('Pair each backup destination with a restore runbook, a rollback owner, and an expected recovery path so incidents do not start with guesswork.') }}
                </p>
            </section>
        </div>

        <div class="mb-6 rounded-2xl border border-brand-gold/35 bg-gradient-to-r from-brand-sand/40 to-white px-5 py-4 shadow-sm">
            <p class="text-sm font-semibold text-brand-ink">{{ __('What a trustworthy database backup setup includes') }}</p>
            <ul class="mt-2 space-y-1 text-sm leading-relaxed text-brand-moss list-disc list-inside">
                <li>{{ __('A storage destination with retention you can explain to the team.') }}</li>
                <li>{{ __('A restore owner and a runbook for the most important production databases.') }}</li>
                <li>{{ __('A simple way to confirm where the latest dump should land before you need it in an incident.') }}</li>
            </ul>
        </div>

        <div class="grid gap-4 lg:grid-cols-3 mb-6">
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm lg:col-span-2">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Available storage destinations') }}</h2>
                @if ($storageDestinations->isEmpty())
                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                        {{ __('No storage destinations yet. Add one before you rely on scheduled exports or manual recovery.') }}
                    </p>
                    <a href="{{ route('profile.backup-configurations') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-sage hover:text-brand-ink">
                        {{ __('Add storage destination') }}
                    </a>
                @else
                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                        {{ trans_choice(':count destination is ready for backup planning and restore drills.|:count destinations are ready for backup planning and restore drills.', $storageDestinations->count(), ['count' => $storageDestinations->count()]) }}
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
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Restore checklist') }}</h2>
                <ul class="mt-3 space-y-2 text-sm leading-relaxed text-brand-moss list-disc list-inside">
                    <li>{{ __('Pick the storage destination before scheduling exports.') }}</li>
                    <li>{{ __('Record the import command or UI path in a runbook.') }}</li>
                    <li>{{ __('Verify who owns the recovery decision for each production site.') }}</li>
                </ul>
            </section>
        </div>

        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-4 py-3 sm:px-6 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Sites in this organization') }}</h2>
                <p class="text-xs text-brand-moss mt-0.5">{{ __('Use each site to define the intended database backup path, storage destination, and restore notes for its primary data.') }}</p>
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
                                <th class="px-4 py-3">{{ __('Backup policy') }}</th>
                                <th class="px-4 py-3">{{ __('Restore readiness') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/10">
                            @foreach ($sites as $site)
                                @php
                                    $trackedDatabaseCount = (int) ($databaseCounts[$site->server_id] ?? 0);
                                    $latestBackup = $latestBackups[(string) $site->server_id] ?? null;
                                @endphp
                                <tr wire:key="db-backup-{{ $site->id }}" class="hover:bg-brand-sand/20">
                                    <td class="px-4 py-3 font-medium text-brand-ink">
                                        <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-brand-sage">{{ $site->name }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss">{{ $site->server?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-brand-moss">
                                        <div class="space-y-1">
                                            <p>
                                                {{ trans_choice(':count tracked database on this server.|:count tracked databases on this server.', $trackedDatabaseCount, ['count' => $trackedDatabaseCount]) }}
                                            </p>
                                            <p class="text-xs text-brand-mist">
                                                @if ($storageDestinations->isNotEmpty())
                                                    {{ __('Use one of your configured storage destinations plus server-side jobs or the BYO agent path for recurring dumps.') }}
                                                @else
                                                    {{ __('Add a storage destination first, then connect server-side jobs or the BYO agent path for recurring dumps.') }}
                                                @endif
                                            </p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-brand-moss">
                                        <div class="space-y-1">
                                            @if ($latestBackup)
                                                <p>
                                                    {{ __('Latest export: :status on :time', [
                                                        'status' => str($latestBackup->status)->replace('_', ' ')->title(),
                                                        'time' => $latestBackup->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                                                    ]) }}
                                                </p>
                                                <p class="text-xs text-brand-mist">
                                                    {{ $latestBackup->serverDatabase?->name ? __('Database: :name', ['name' => $latestBackup->serverDatabase->name]) : __('Most recent server-level database export is available for review.') }}
                                                </p>
                                            @else
                                                <p>{{ __('No recorded export yet. Document the restore command and owner before this site is production-critical.') }}</p>
                                                <p class="text-xs text-brand-mist">{{ __('Project runbooks are a good place to record rollback or import steps.') }}</p>
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
            <p class="text-sm font-semibold text-brand-ink">{{ __('Recovery note') }}</p>
            <p class="mt-2 text-sm leading-relaxed text-brand-moss max-w-3xl">
                {{ __('Automated dumps and uploads still depend on the BYO agent or server-side jobs on the target machine. Until that path is fully automated on every server, use Storage destinations for S3-compatible targets, keep restore commands in your runbooks, and treat this page as the shared source of truth for backup expectations.') }}
            </p>
        </div>
    </div>
</div>
