<div class="mx-auto max-w-7xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'icon' => 'globe-alt'],
    ]" />

    <x-page-header
        :eyebrow="__('Edge fleet')"
        :title="__('Edge sites')"
        :description="__('Static and SSG apps on the dply Edge platform across :org.', ['org' => $org->name])"
        flush
        compact
        toolbar
    >
        <x-slot name="leading">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <x-heroicon-o-globe-alt class="h-7 w-7 text-brand-ink" aria-hidden="true" />
            </span>
        </x-slot>
        @if ($edgeEnabled)
            <x-slot name="actions">
                <a href="{{ route('edge.usage') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Usage') }}
                </a>
                <a href="{{ route('edge.templates') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-rectangle-stack class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Templates') }}
                </a>
                <a href="{{ route('edge.import') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Import') }}
                </a>
                <a href="{{ route('edge.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest">
                    <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                    {{ __('Deploy an edge app') }}
                </a>
            </x-slot>
        @endif
    </x-page-header>

    @if ($edgeEnabled)
        <div class="mt-8 mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('All sites') }}
                </div>
                <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $totals['all'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-check-badge class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                    {{ __('Active') }}
                </div>
                <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $totals['active'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Provisioning') }}
                </div>
                <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $totals['provisioning'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    {{ __('Previews') }}
                </div>
                <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $totals['previews'] }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <div class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 {{ $totals['failed'] > 0 ? 'text-rose-600' : 'text-brand-mist' }}" aria-hidden="true" />
                    {{ __('Failed') }}
                </div>
                <p class="mt-1 text-xl font-semibold text-brand-ink">{{ $totals['failed'] }}</p>
            </div>
        </div>
    @endif

    @unless ($edgeEnabled)
        <div class="dply-card relative p-8 text-center">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                <x-heroicon-o-globe-alt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Edge') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-brand-moss">
                {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
        </div>
    @else
        <nav class="mb-5 flex flex-wrap gap-2 text-xs">
            @php
                $tabs = [
                    ['key' => 'all', 'label' => __('All'), 'count' => $totals['all']],
                    ['key' => 'previews', 'label' => __('Previews'), 'count' => $totals['previews']],
                    ['key' => 'provisioning', 'label' => __('Provisioning'), 'count' => $totals['provisioning']],
                    ['key' => 'failed', 'label' => __('Failed'), 'count' => $totals['failed']],
                ];
            @endphp
            @foreach ($tabs as $tab)
                <button type="button" wire:click="$set('filter', '{{ $tab['key'] }}')" class="rounded-full border px-3 py-1.5 font-semibold transition {{ $filter === $tab['key'] ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' }}">
                    {{ $tab['label'] }}
                    <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </nav>

        @if ($sites->isEmpty())
            <div class="dply-card p-8 text-center">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink shadow-sm">
                    <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="mt-4 text-base font-semibold text-brand-ink">{{ __('No edge sites found') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Git-connected static and SSG apps you deploy via dply Edge will appear here.') }}</p>
                <a href="{{ route('edge.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Deploy your first edge app') }}
                </a>
            </div>
        @else
            <div class="space-y-3 lg:hidden">
                @foreach ($sites as $site)
                    @php
                        $edgeMeta = $site->edgeMeta();
                        $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
                        $buildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : null;
                        $framework = trim((string) ($buildSpec['framework'] ?? ''));
                        $runtimeMode = (string) ($edgeMeta['runtime_mode'] ?? 'static');
                        $runtimeLabel = $runtimeMode === 'hybrid' ? __('Hybrid') : __('Static');
                        $statusBadge = match ($site->status) {
                            \App\Models\Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                            \App\Models\Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                            \App\Models\Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                            default => 'bg-brand-sand/60 text-brand-moss',
                        };
                        $statusLabel = match ($site->status) {
                            \App\Models\Site::STATUS_EDGE_ACTIVE => __('Active'),
                            \App\Models\Site::STATUS_EDGE_PROVISIONING => __('Provisioning'),
                            \App\Models\Site::STATUS_EDGE_FAILED => __('Failed'),
                            default => str_replace('_', ' ', (string) $site->status),
                        };
                        $liveUrl = $site->edgeLiveUrl();
                        $hostname = parse_url((string) $liveUrl, PHP_URL_HOST);
                        $rowPreviewBranch = $edgeMeta['preview_branch'] ?? null;
                        $rowPreviewPr = $edgeMeta['preview_pr_number'] ?? null;
                    @endphp
                    <article class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:underline">{{ $site->name }}</a>
                                @if ($rowPreviewBranch)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-forest dark:bg-brand-sage/20 dark:text-brand-sage">
                                        @if ($rowPreviewPr)
                                            PR #{{ $rowPreviewPr }}
                                        @else
                                            {{ __('Preview') }}
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </div>

                        <dl class="mt-3 space-y-2 text-xs text-brand-moss">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Source') }}</dt>
                                <dd class="font-mono text-right">{{ $sourceSpec ? (($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main')) : '—' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Runtime') }}</dt>
                                <dd class="inline-flex items-center gap-1.5">
                                    <span>{{ $runtimeLabel }}</span>
                                    @if ($framework !== '' && strtolower($framework) !== 'unknown')
                                        <span class="inline-flex rounded-full border border-brand-ink/15 bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ str($framework)->replace(['_', '-'], ' ')->title() }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Hostname') }}</dt>
                                <dd class="max-w-[70%] truncate text-right font-medium text-brand-ink">{{ $hostname ?: __('Pending') }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                wire:click="openQuickLookModal('{{ $site->id }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-bolt class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Status') }}
                            </button>
                            <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                                <x-heroicon-o-eye class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Open') }}
                            </a>
                            @can('delete', $site)
                                <button type="button" wire:click="openDeleteSiteModal('{{ $site->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 transition hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-950/50">
                                    <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="dply-card hidden overflow-hidden lg:block">
                <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                    <thead class="bg-brand-sand/30 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                        <tr>
                            <th class="px-4 py-3">{{ __('App') }}</th>
                            <th class="px-4 py-3">{{ __('Source') }}</th>
                            <th class="px-4 py-3">{{ __('Runtime') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('Hostname') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                        @foreach ($sites as $site)
                            @php
                                $edgeMeta = $site->edgeMeta();
                                $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
                                $buildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : null;
                                $framework = trim((string) ($buildSpec['framework'] ?? ''));
                                $runtimeMode = (string) ($edgeMeta['runtime_mode'] ?? 'static');
                                $runtimeLabel = $runtimeMode === 'hybrid' ? __('Hybrid') : __('Static');
                                $statusBadge = match ($site->status) {
                                    \App\Models\Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                                    \App\Models\Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                                    \App\Models\Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                                    default => 'bg-brand-sand/60 text-brand-moss',
                                };
                                $statusLabel = match ($site->status) {
                                    \App\Models\Site::STATUS_EDGE_ACTIVE => __('Active'),
                                    \App\Models\Site::STATUS_EDGE_PROVISIONING => __('Provisioning'),
                                    \App\Models\Site::STATUS_EDGE_FAILED => __('Failed'),
                                    default => str_replace('_', ' ', (string) $site->status),
                                };
                                $liveUrl = $site->edgeLiveUrl();
                                $hostname = parse_url((string) $liveUrl, PHP_URL_HOST);
                                $rowPreviewBranch = $edgeMeta['preview_branch'] ?? null;
                                $rowPreviewPr = $edgeMeta['preview_pr_number'] ?? null;
                            @endphp
                            <tr>
                                <td class="px-4 py-3.5">
                                    <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ $site->name }}</a>
                                    @if ($rowPreviewBranch)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-forest dark:bg-brand-sage/20 dark:text-brand-sage">
                                            @if ($rowPreviewPr)
                                                PR #{{ $rowPreviewPr }}
                                            @else
                                                {{ __('Preview') }}
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs text-brand-moss">
                                    @if ($sourceSpec)
                                        <div>{{ $sourceSpec['repo'] ?? '?' }}</div>
                                        <div class="text-[11px]">{{ '@'.($sourceSpec['branch'] ?? 'main') }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink">
                                        <span>{{ $runtimeLabel }}</span>
                                        @if ($framework !== '' && strtolower($framework) !== 'unknown')
                                            <span class="inline-flex rounded-full border border-brand-ink/15 bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ str($framework)->replace(['_', '-'], ' ')->title() }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    @if ($liveUrl)
                                        <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="inline-flex flex-col text-xs text-brand-sage hover:underline">
                                            <span class="font-medium">{{ $hostname ?: $liveUrl }}</span>
                                            <span class="text-[11px] text-brand-moss">{{ __('Open live URL') }}</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-brand-mist">{{ __('Pending') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            wire:click="openQuickLookModal('{{ $site->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                                            title="{{ __('Peek at the live build/provisioning status without leaving this list.') }}"
                                        >
                                            <x-heroicon-o-eye class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Status') }}
                                        </button>
                                        <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Open') }}
                                        </a>
                                        @can('delete', $site)
                                            <button type="button" wire:click="openDeleteSiteModal('{{ $site->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 transition hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300 dark:hover:bg-rose-950/50">
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                                                {{ __('Delete') }}
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endunless

    <x-modal
        name="edge-index-delete-site-confirmation"
        :show="false"
        maxWidth="lg"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel"
        focusable
    >
        @php
            $deleteModes = [
                'now' => ['label' => __('Delete now'), 'help' => __('Immediate teardown')],
                'in_30' => ['label' => __('In 30 minutes'), 'help' => __('30-minute grace window')],
                'scheduled' => ['label' => __('Schedule date/time'), 'help' => __('Pick a future date')],
            ];
        @endphp
        <div class="border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Danger zone') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Delete this Edge site?') }}</h2>
            <p class="mt-2 text-sm leading-6 text-brand-moss">
                @if ($deleteCandidate)
                    {{ __(':name will be removed from dply. Active deployments and preview deployments stop serving traffic after teardown.', ['name' => $deleteCandidate->name]) }}
                @else
                    {{ __('This Edge site will be removed from dply. Active deployments and preview deployments stop serving traffic after teardown.') }}
                @endif
            </p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-brand-moss">
                <li>{{ __('Site configuration and edge deployment records are deleted.') }}</li>
                <li>{{ __('Routing and published assets for this site are torn down.') }}</li>
                <li>{{ __('This action cannot be undone.') }}</li>
            </ul>
        </div>
        <div class="space-y-5 px-6 py-5">
            <fieldset class="space-y-2">
                <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('When to delete') }}</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($deleteModes as $mode => $meta)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model.live="deleteMode" value="{{ $mode }}" class="peer sr-only" />
                            <div class="rounded-xl border-2 border-zinc-200 bg-white px-3 py-2.5 text-sm transition peer-checked:border-red-500 peer-checked:bg-red-50 peer-focus-visible:ring-2 peer-focus-visible:ring-red-500/40 hover:border-zinc-300">
                                <p class="font-semibold text-brand-ink">{{ $meta['label'] }}</p>
                                <p class="mt-0.5 text-[11px] text-brand-moss">{{ $meta['help'] }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($deleteMode === 'scheduled')
                <div class="space-y-2">
                    <label for="edge-scheduled-delete-at" class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ __('Deletion date/time') }}</label>
                    <input
                        id="edge-scheduled-delete-at"
                        type="datetime-local"
                        wire:model.live="scheduledDeleteAt"
                        min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}"
                        class="block w-full rounded-xl border-zinc-200 bg-white shadow-sm focus:border-red-500 focus:ring-red-500"
                    />
                    <p class="text-[11px] text-brand-mist">{{ __('Uses your app timezone and must be in the future.') }}</p>
                    @error('scheduledDeleteAt')
                        <p class="text-xs text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            @elseif ($deleteMode === 'in_30')
                <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900">
                    {{ __('The site will be deleted in 30 minutes.') }}
                </div>
            @endif
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeDeleteSiteModal">
                {{ __('Cancel') }}
            </x-secondary-button>
            <x-danger-button type="button" wire:click="deleteSite" wire:loading.attr="disabled" wire:target="deleteSite">
                <span wire:loading.remove wire:target="deleteSite">
                    @if ($deleteMode === 'scheduled')
                        {{ __('Schedule deletion') }}
                    @elseif ($deleteMode === 'in_30')
                        {{ __('Delete in 30 minutes') }}
                    @else
                        {{ __('Delete Edge site') }}
                    @endif
                </span>
                <span wire:loading wire:target="deleteSite">{{ __('Deleting…') }}</span>
            </x-danger-button>
        </div>
    </x-modal>

    {{-- Quick-look modal — peek at the live BuildJourney for any site in the
         list without bouncing into its workspace. Polls itself via the
         nested BuildJourney component (1s log tail), and the link in the
         footer takes you to the full workspace if you want to go deeper. --}}
    <x-modal name="quick-look-edge-site" :show="false" maxWidth="3xl" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel" focusable>
        <div class="flex items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-4">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Quick look') }}</p>
                <h2 class="mt-1 text-base font-semibold text-brand-ink truncate">
                    {{ $quickLookSite?->name ?? __('Edge site') }}
                </h2>
                @if ($quickLookSite && $quickLookSite->edgeLiveUrl())
                    <a
                        href="{{ $quickLookSite->edgeLiveUrl() }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-0.5 inline-flex items-center gap-1 font-mono text-[11px] text-brand-moss hover:text-brand-ink"
                    >
                        {{ preg_replace('#^https?://#', '', $quickLookSite->edgeLiveUrl()) }}
                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 opacity-70" />
                    </a>
                @endif
            </div>
            <button type="button" wire:click="closeQuickLookModal" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="px-6 py-5">
            @if ($quickLookSite === null)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-5 py-8 text-center text-sm text-brand-moss">
                    {{ __('Site not found.') }}
                </div>
            @elseif ($quickLookDeploymentId === null)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-5 py-8 text-center text-sm text-brand-moss">
                    {{ __('No deployments yet for this site.') }}
                </div>
            @elseif ($quickLookStats !== null)
                {{-- Stats mode — site is past the in-flight build phase, so
                     mounting the live journey would be a noisy distraction.
                     Show the headline counts + last-deploy metadata. --}}
                @php
                    $latest = $quickLookStats['latest'];
                    $latestStatusTone = match ($latest?->status ?? null) {
                        \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800',
                        \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800',
                        \App\Models\EdgeDeployment::STATUS_SUPERSEDED => 'bg-brand-sand/60 text-brand-moss',
                        default => 'bg-sky-100 text-sky-800',
                    };
                @endphp
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Status') }}</p>
                        <p class="mt-1 text-sm font-semibold capitalize text-brand-ink">{{ str_replace('_', ' ', (string) $quickLookSite->status) }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Total deploys') }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums text-brand-ink">{{ number_format($quickLookStats['total_deploys']) }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Live') }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums text-emerald-700">{{ number_format($quickLookStats['live_deploys']) }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/60 px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Failed') }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums {{ $quickLookStats['failed_deploys'] > 0 ? 'text-rose-700' : 'text-brand-mist' }}">{{ number_format($quickLookStats['failed_deploys']) }}</p>
                    </div>
                </div>

                @if ($latest !== null)
                    <section class="dply-card mt-4 overflow-hidden">
                        <div class="flex items-baseline justify-between gap-3 border-b border-brand-ink/10 px-5 py-3">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Latest deployment') }}</h3>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $latestStatusTone }}">
                                {{ str_replace('_', ' ', (string) $latest->status) }}
                            </span>
                        </div>
                        <dl class="grid grid-cols-1 gap-3 px-5 py-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Commit') }}</dt>
                                <dd class="mt-0.5 font-mono text-xs text-brand-ink break-all">{{ $latest->git_commit ? substr($latest->git_commit, 0, 12) : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Branch') }}</dt>
                                <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $latest->git_branch ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Published') }}</dt>
                                <dd class="mt-0.5 text-xs text-brand-ink">{{ $latest->published_at ? $latest->published_at->diffForHumans() : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Created') }}</dt>
                                <dd class="mt-0.5 text-xs text-brand-ink">{{ $latest->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>
                        @if ($latest->status === \App\Models\EdgeDeployment::STATUS_FAILED && $latest->failure_reason)
                            <div class="border-t border-brand-ink/10 bg-rose-50/60 px-5 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-rose-700">{{ __('Failure') }}</p>
                                <p class="mt-1 break-words font-mono text-[11px] leading-5 text-rose-900">{{ $latest->failure_reason }}</p>
                            </div>
                        @endif
                    </section>
                @endif
            @else
                {{-- In-flight build — surface the live journey card. --}}
                @livewire('edge.build-journey', ['deploymentId' => $quickLookDeploymentId], key('quick-look-journey-'.$quickLookDeploymentId))
            @endif
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3">
            <span class="text-[11px] text-brand-mist">{{ __('Live — updates every second while the build is running.') }}</span>
            @if ($quickLookSite)
                <a
                    href="{{ route('sites.show', ['server' => $quickLookSite->server, 'site' => $quickLookSite]) }}"
                    wire:navigate
                    class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage"
                >
                    {{ __('Open workspace →') }}
                </a>
            @endif
        </div>
    </x-modal>
</div>
