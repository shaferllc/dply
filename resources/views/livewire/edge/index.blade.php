<div class="mx-auto max-w-6xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'icon' => 'globe-alt'],
    ]" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Edge sites') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Static and SSG apps on the dply Edge platform across :org.', ['org' => $org->name]) }}</p>
        </div>
        @if ($edgeEnabled)
            <a href="{{ route('edge.create') }}" wire:navigate class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                {{ __('Deploy an edge app') }}
            </a>
        @endif
    </header>

    @unless ($edgeEnabled)
        <div class="relative rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
            <span class="absolute end-6 top-6 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                {{ __('Coming soon') }}
            </span>
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-xl bg-slate-100 text-slate-500 ring-1 ring-slate-200">
                <x-heroicon-o-globe-alt class="h-8 w-8 shrink-0" aria-hidden="true" />
            </span>
            <p class="mt-5 text-lg font-semibold text-slate-900">{{ __('Edge') }}</p>
            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">
                {{ __('JavaScript frameworks, static sites, previews, and CDN-style delivery.') }}
            </p>
            <p class="mt-5 text-sm font-medium text-slate-500">{{ __('Not available yet') }}</p>
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
            <button type="button" wire:click="$set('filter', '{{ $tab['key'] }}')" class="rounded-full border px-3 py-1.5 font-semibold {{ $filter === $tab['key'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                {{ $tab['label'] }}
                <span class="ml-1 font-mono opacity-80">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </nav>

    @if ($sites->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600 shadow-sm">
            <p class="font-semibold text-slate-900">{{ __('No edge sites found') }}</p>
            <p class="mt-1">{{ __('Git-connected static and SSG apps you deploy via dply Edge will appear here.') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('App') }}</th>
                        <th class="px-4 py-3">{{ __('Source') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Live URL') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-800">
                    @foreach ($sites as $site)
                        @php
                            $edgeMeta = $site->edgeMeta();
                            $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
                            $statusBadge = match ($site->status) {
                                \App\Models\Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800',
                                \App\Models\Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $statusLabel = match ($site->status) {
                                \App\Models\Site::STATUS_EDGE_ACTIVE => __('Active'),
                                \App\Models\Site::STATUS_EDGE_PROVISIONING => __('Provisioning'),
                                \App\Models\Site::STATUS_EDGE_FAILED => __('Failed'),
                                default => str_replace('_', ' ', (string) $site->status),
                            };
                            $liveUrl = $site->edgeLiveUrl();
                            $rowPreviewBranch = $edgeMeta['preview_branch'] ?? null;
                            $rowPreviewPr = $edgeMeta['preview_pr_number'] ?? null;
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site]) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ $site->name }}</a>
                                @if ($rowPreviewBranch)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.12em] text-indigo-800">
                                        @if ($rowPreviewPr)
                                            PR #{{ $rowPreviewPr }}
                                        @else
                                            {{ __('Preview') }}
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                @if ($sourceSpec)
                                    {{ ($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $statusBadge }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($liveUrl)
                                    <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="break-all text-xs text-sky-700 hover:underline">{{ $liveUrl }}</a>
                                @else
                                    <span class="text-xs text-slate-400">{{ __('Pending') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @endunless
</div>
