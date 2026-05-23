<div class="mx-auto max-w-7xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'icon' => 'globe-alt'],
    ]" />

    <header class="mb-6 rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm ring-1 ring-slate-200/60 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80 dark:ring-zinc-700/40">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edge fleet') }}</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-900 dark:text-zinc-100">{{ __('Edge sites') }}</h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-zinc-300">{{ __('Static and SSG apps on the dply Edge platform across :org.', ['org' => $org->name]) }}</p>
            </div>
            @if ($edgeEnabled)
                <a href="{{ route('edge.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-ink/40 dark:bg-brand-sky dark:text-brand-ink dark:hover:bg-brand-sky/90">
                    <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                    {{ __('Deploy an edge app') }}
                </a>
            @endif
        </div>

        @if ($edgeEnabled)
            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-slate-50/90 p-4 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-zinc-400">{{ __('All sites') }}</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-zinc-100">{{ $totals['all'] }}</p>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/30">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300">{{ __('Active') }}</p>
                    <p class="mt-1 text-xl font-semibold text-emerald-900 dark:text-emerald-200">{{ $totals['active'] }}</p>
                </div>
                <div class="rounded-2xl border border-sky-200 bg-sky-50/80 p-4 dark:border-sky-900/40 dark:bg-sky-950/30">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">{{ __('Provisioning') }}</p>
                    <p class="mt-1 text-xl font-semibold text-sky-900 dark:text-sky-200">{{ $totals['provisioning'] }}</p>
                </div>
            </div>
        @endif
    </header>

    @unless ($edgeEnabled)
        <div class="relative rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600 dark:bg-zinc-800 dark:text-zinc-300">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl bg-slate-100 text-slate-500 ring-1 ring-slate-200 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700">
                <x-heroicon-o-globe-alt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-slate-900 dark:text-zinc-100">{{ __('Edge') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600 dark:text-zinc-300">
                {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-slate-500 dark:text-zinc-400">{{ __('Not available yet') }}</p>
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
                <button type="button" wire:click="$set('filter', '{{ $tab['key'] }}')" class="rounded-full border px-3 py-1.5 font-semibold transition {{ $filter === $tab['key'] ? 'border-brand-ink bg-brand-ink text-white dark:border-brand-sky dark:bg-brand-sky dark:text-brand-ink' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600' }}">
                    {{ $tab['label'] }}
                    <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </nav>

        @if ($sites->isEmpty())
            <div class="rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-zinc-800 dark:text-zinc-300">
                    <x-heroicon-o-rocket-launch class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="mt-4 text-base font-semibold text-slate-900 dark:text-zinc-100">{{ __('No edge sites found') }}</p>
                <p class="mt-1 text-sm text-slate-600 dark:text-zinc-300">{{ __('Git-connected static and SSG apps you deploy via dply Edge will appear here.') }}</p>
                <a href="{{ route('edge.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800">
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
                            default => 'bg-slate-100 text-slate-700 dark:bg-zinc-800 dark:text-zinc-300',
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
                    <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="text-sm font-semibold text-slate-900 hover:underline dark:text-zinc-100">{{ $site->name }}</a>
                                @if ($rowPreviewBranch)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">
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

                        <dl class="mt-3 space-y-2 text-xs text-slate-600 dark:text-zinc-300">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-zinc-400">{{ __('Source') }}</dt>
                                <dd class="font-mono text-right">{{ $sourceSpec ? (($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main')) : '—' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-zinc-400">{{ __('Runtime') }}</dt>
                                <dd class="inline-flex items-center gap-1.5">
                                    <span>{{ $runtimeLabel }}</span>
                                    @if ($framework !== '' && strtolower($framework) !== 'unknown')
                                        <span class="inline-flex rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ str($framework)->replace(['_', '-'], ' ')->title() }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="font-semibold uppercase tracking-[0.14em] text-slate-500 dark:text-zinc-400">{{ __('Hostname') }}</dt>
                                <dd class="max-w-[70%] truncate text-right font-medium text-slate-700 dark:text-zinc-200">{{ $hostname ?: __('Pending') }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
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

            <div class="hidden overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950 lg:block">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-zinc-800">
                    <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 dark:bg-zinc-900 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3">{{ __('App') }}</th>
                            <th class="px-4 py-3">{{ __('Source') }}</th>
                            <th class="px-4 py-3">{{ __('Runtime') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('Hostname') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-800 dark:divide-zinc-800 dark:text-zinc-200">
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
                                    default => 'bg-slate-100 text-slate-700 dark:bg-zinc-800 dark:text-zinc-300',
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
                                    <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="font-medium text-slate-900 hover:underline dark:text-zinc-100">{{ $site->name }}</a>
                                    @if ($rowPreviewBranch)
                                        <span class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">
                                            @if ($rowPreviewPr)
                                                PR #{{ $rowPreviewPr }}
                                            @else
                                                {{ __('Preview') }}
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 font-mono text-xs text-slate-500 dark:text-zinc-400">
                                    @if ($sourceSpec)
                                        <div>{{ $sourceSpec['repo'] ?? '?' }}</div>
                                        <div class="text-[11px]">{{ '@'.($sourceSpec['branch'] ?? 'main') }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-700 dark:text-zinc-300">
                                        <span>{{ $runtimeLabel }}</span>
                                        @if ($framework !== '' && strtolower($framework) !== 'unknown')
                                            <span class="inline-flex rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ str($framework)->replace(['_', '-'], ' ')->title() }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-4 py-3.5">
                                    @if ($liveUrl)
                                        <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="inline-flex flex-col text-xs text-sky-700 hover:underline dark:text-sky-300">
                                            <span class="font-medium">{{ $hostname ?: $liveUrl }}</span>
                                            <span class="text-[11px] text-slate-500 dark:text-zinc-400">{{ __('Open live URL') }}</span>
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-400 dark:text-zinc-500">{{ __('Pending') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">
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
</div>
