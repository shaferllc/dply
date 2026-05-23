<div class="mx-auto max-w-6xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Cloud apps'), 'icon' => 'cloud'],
    ]" />

    <header class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Cloud sites') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Container apps deployed on the dply cloud platform across :org.', ['org' => $org->name]) }}</p>
        </div>
        <a href="{{ route('cloud.create') }}" wire:navigate class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
            {{ __('Deploy a container app') }}
        </a>
    </header>

    @if (! $hasAnyBackendCredential)
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 text-sm text-amber-900">
            <p class="font-semibold">{{ __('No container backend connected') }}</p>
            <p class="mt-1">{{ __('Connect DigitalOcean App Platform or AWS App Runner credentials to deploy your first container.') }}</p>
            <p class="mt-3">
                <a href="{{ route('credentials.index', ['provider' => 'digitalocean_app_platform']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect DigitalOcean') }}</a>
                <span class="mx-2 text-amber-400">·</span>
                <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect AWS App Runner') }}</a>
            </p>
        </div>
    @endif

    <nav class="mb-5 flex flex-wrap gap-2 text-xs">
        @php
            $tabs = [
                ['key' => 'all', 'label' => __('All'), 'count' => $totals['all']],
                ['key' => 'source', 'label' => __('Source'), 'count' => $totals['source'] ?? 0],
                ['key' => 'image', 'label' => __('Image'), 'count' => $totals['image'] ?? 0],
                ['key' => 'previews', 'label' => __('Previews'), 'count' => $totals['previews'] ?? 0],
                ['key' => 'digitalocean_app_platform', 'label' => 'DO App Platform', 'count' => $totals['digitalocean_app_platform']],
                ['key' => 'aws_app_runner', 'label' => 'AWS App Runner', 'count' => $totals['aws_app_runner']],
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
            <p class="font-semibold text-slate-900">{{ __('No cloud sites found') }}</p>
            <p class="mt-1">{{ __('Container apps you deploy via the dply cloud will appear here.') }}</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('App') }}</th>
                        <th class="px-4 py-3">{{ __('Backend') }}</th>
                        <th class="px-4 py-3">{{ __('Region') }}</th>
                        <th class="px-4 py-3">{{ __('Image / source') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Live URL') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-800">
                    @foreach ($sites as $site)
                        @php
                            $statusBadge = match ($site->status) {
                                \App\Models\Site::STATUS_CONTAINER_ACTIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\Site::STATUS_CONTAINER_PROVISIONING => 'bg-sky-100 text-sky-800',
                                \App\Models\Site::STATUS_CONTAINER_FAILED => 'bg-rose-100 text-rose-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $statusLabel = match ($site->status) {
                                \App\Models\Site::STATUS_CONTAINER_ACTIVE => __('Active'),
                                \App\Models\Site::STATUS_CONTAINER_PROVISIONING => __('Provisioning'),
                                \App\Models\Site::STATUS_CONTAINER_FAILED => __('Failed'),
                                default => str_replace('_', ' ', (string) $site->status),
                            };
                            $backendLabel = match ($site->container_backend) {
                                'digitalocean_app_platform' => 'DO App Platform',
                                'aws_app_runner' => 'AWS App Runner',
                                default => $site->container_backend ?? '—',
                            };
                            $liveUrl = $site->containerLiveUrl();
                            $rowSource = is_array($site->meta['container']['source'] ?? null) ? $site->meta['container']['source'] : null;
                            $rowPreviewBranch = $site->meta['container']['preview_branch'] ?? null;
                            $rowPreviewPr = $site->meta['container']['preview_pr_number'] ?? null;
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
                            <td class="px-4 py-3 text-slate-700">{{ $backendLabel }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $site->container_region ?: '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                @if ($rowSource)
                                    {{ ($rowSource['repo'] ?? '?').'@'.($rowSource['branch'] ?? 'main') }}
                                @else
                                    {{ $site->container_image ?: '—' }}
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
</div>
