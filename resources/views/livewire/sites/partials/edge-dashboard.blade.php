@php
    $edgeMeta = $site->edgeMeta();
    $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
    $liveUrl = $site->edgeLiveUrl();
    $deployments = $site->edgeDeployments()->limit(10)->get();
    $statusBadgeClass = match ($site->status) {
        \App\Models\Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800',
        \App\Models\Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800',
        \App\Models\Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $statusLabel = match ($site->status) {
        \App\Models\Site::STATUS_EDGE_ACTIVE => __('Active'),
        \App\Models\Site::STATUS_EDGE_PROVISIONING => __('Building'),
        \App\Models\Site::STATUS_EDGE_FAILED => __('Failed'),
        default => str_replace('_', ' ', (string) $site->status),
    };
    $attachedDomains = is_array($edgeMeta['routing']['custom_domains'] ?? null) ? $edgeMeta['routing']['custom_domains'] : [];
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-indigo-700">{{ __('Dply Edge') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Static / SSG deployment') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Git-connected builds published to global edge delivery (R2 + Cloudflare Worker).') }}</p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
    </div>

    @if (! empty($edgeMeta['last_error']))
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
            <p class="font-semibold">{{ __('Last error') }}</p>
            <p class="mt-1 break-words">{{ $edgeMeta['last_error'] }}</p>
        </div>
    @endif

    <dl class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Live URL') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">
                @if ($liveUrl)
                    <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="break-all text-sky-700 hover:underline">{{ $liveUrl }}</a>
                @else
                    <span class="text-slate-500">{{ __('Pending first deploy') }}</span>
                @endif
            </dd>
        </div>
        @if ($sourceSpec)
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Source') }}</dt>
                <dd class="mt-1 font-mono text-xs text-slate-900">{{ ($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main') }}</dd>
            </div>
        @endif
    </dl>

    <div class="flex flex-wrap items-center gap-3">
        <button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" wire:target="redeployEdge" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
            <span wire:loading.remove wire:target="redeployEdge">{{ __('Redeploy') }}</span>
            <span wire:loading wire:target="redeployEdge">{{ __('Queueing…') }}</span>
        </button>
    </div>

    @if ($deployments->isNotEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Deploy history') }}</p>
            <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                @foreach ($deployments as $deployment)
                    @php
                        $isActive = ($edgeMeta['active_deployment_id'] ?? null) === $deployment->id;
                        $depClass = match ($deployment->status) {
                            \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800',
                            \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800',
                            default => 'bg-slate-100 text-slate-700',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                        <div>
                            <p class="font-mono text-slate-900">{{ substr((string) $deployment->id, 0, 12) }}</p>
                            @if ($deployment->published_at)
                                <p class="text-[10px] text-slate-500">{{ $deployment->published_at->diffForHumans() }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $depClass }}">{{ $deployment->status }}</span>
                            @if ($isActive)
                                <span class="text-[10px] font-semibold text-emerald-700">{{ __('Current') }}</span>
                            @elseif ($deployment->status === \App\Models\EdgeDeployment::STATUS_LIVE || $deployment->status === \App\Models\EdgeDeployment::STATUS_SUPERSEDED)
                                <button type="button" wire:click="rollbackEdgeDeployment('{{ $deployment->id }}')" class="text-[11px] font-medium text-sky-700 hover:text-sky-900">{{ __('Roll back') }}</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($sourceSpec && empty($edgeMeta['preview_parent_site_id']))
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('GitHub webhook') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Paste this URL + webhook secret for push and pull request previews.') }}</p>
            <input type="text" readonly value="{{ $site->edgeGithubHookUrl() }}" class="mt-3 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" onclick="this.select()" />
        </div>

        @php $previews = \App\Actions\Edge\CreateEdgePreviewSite::listForParent($site); @endphp
        @if ($previews->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Preview deployments') }}</p>
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                    @foreach ($previews as $preview)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <div>
                                <p class="font-mono">{{ $preview->edgeMeta()['preview_branch'] ?? '—' }}</p>
                                @if ($preview->edgeLiveUrl())
                                    <a href="{{ $preview->edgeLiveUrl() }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $preview->edgeLiveUrl() }}</a>
                                @endif
                            </div>
                            <button type="button" wire:click="tearDownEdgePreview('{{ $preview->id }}')" class="text-[11px] font-medium text-rose-700">{{ __('Tear down') }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Custom domains') }}</p>
        @if ($attachedDomains !== [])
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($attachedDomains as $hostname => $info)
                    <li class="flex items-center justify-between px-3 py-2 text-sm">
                        <span class="font-mono">{{ $hostname }}</span>
                        <button type="button" wire:click="detachEdgeDomain('{{ $hostname }}')" class="text-xs font-medium text-rose-700">{{ __('Remove') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="flex flex-col gap-2 sm:flex-row">
            <input type="text" wire:model="edge_domain_input" class="block w-full rounded-md border-slate-300 font-mono text-sm" placeholder="www.example.com" />
            <button type="button" wire:click="attachEdgeDomain" wire:loading.attr="disabled" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{{ __('Attach') }}</button>
        </div>
    </div>

    <div class="flex justify-end border-t border-slate-200 pt-4">
        <button type="button" wire:click="openEdgeTeardownModal" class="text-sm font-medium text-rose-700 hover:text-rose-900">{{ __('Tear down edge site') }}</button>
    </div>
</section>

<x-modal name="edge-teardown-confirmation">
    <p>{{ __('Permanently delete this Edge site? All deployments and edge routing entries will be removed.') }}</p>
    <x-danger-button wire:click="tearDownEdge">{{ __('Delete Edge site') }}</x-danger-button>
</x-modal>
