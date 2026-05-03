@php
    $containerMeta = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
    $sourceSpec = is_array($containerMeta['source'] ?? null) ? $containerMeta['source'] : null;
    $isSourceMode = $sourceSpec !== null;
    $liveUrl = $site->containerLiveUrl();
    $backendLabel = match ($site->container_backend) {
        'digitalocean_app_platform' => 'DigitalOcean App Platform',
        'aws_app_runner' => 'AWS App Runner',
        default => $site->container_backend ?? '—',
    };
    $statusBadgeClass = match ($site->status) {
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
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Dply edge') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Container deployment') }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                @if ($isSourceMode)
                    {{ __('This site runs from a GitHub repo. The backend builds + deploys on every push to the chosen branch.') }}
                @else
                    {{ __('This site runs as a container on a managed backend. Roll out a new image tag or tear it down here.') }}
                @endif
            </p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $statusBadgeClass }}">
            {{ $statusLabel }}
        </span>
    </div>

    @if (! empty($containerMeta['last_error']))
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
            <p class="font-semibold">{{ __('Last error') }}</p>
            <p class="mt-1 break-words">{{ $containerMeta['last_error'] }}</p>
            @if (! empty($containerMeta['last_error_at']))
                <p class="mt-1 text-rose-700">{{ __('At :at', ['at' => $containerMeta['last_error_at']]) }}</p>
            @endif
        </div>
    @endif

    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Backend') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $backendLabel }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Region') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $site->container_region ?: '—' }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Port') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $site->container_port ?: '—' }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Live URL') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">
                @if ($liveUrl)
                    <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="break-all text-sky-700 hover:underline">{{ $liveUrl }}</a>
                @else
                    <span class="text-slate-500">{{ __('Pending — backend has not assigned an ingress URL yet.') }}</span>
                @endif
            </dd>
        </div>
    </dl>

    @if ($isSourceMode)
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Source') }}</p>
            <dl class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Repository') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-900">
                        <a href="https://github.com/{{ $sourceSpec['repo'] ?? '' }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $sourceSpec['repo'] ?? '—' }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Branch') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-slate-900">{{ $sourceSpec['branch'] ?? 'main' }}</dd>
                </div>
                @if (! empty($sourceSpec['dockerfile_path']))
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Dockerfile') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-slate-900">{{ $sourceSpec['dockerfile_path'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Auto-deploy on push') }}</dt>
                    <dd class="mt-1 text-xs">
                        @if (! array_key_exists('deploy_on_push', $sourceSpec) || $sourceSpec['deploy_on_push'])
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('Enabled') }}</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-700">{{ __('Disabled') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-slate-500">{{ __('Push to the branch above to trigger a build and deploy. Or manually re-roll the latest commit:') }}</p>
                <button type="button" wire:click="redeployContainer" wire:loading.attr="disabled" wire:target="redeployContainer" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="redeployContainer">{{ __('Redeploy from latest') }}</span>
                    <span wire:loading wire:target="redeployContainer">{{ __('Queueing…') }}</span>
                </button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <label for="container_image_input" class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Image reference') }}</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                <input id="container_image_input" wire:model="container_image_input" type="text" class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="ghcr.io/acme/api:v1.2.3" />
                <button type="button" wire:click="redeployContainer" wire:loading.attr="disabled" wire:target="redeployContainer" class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="redeployContainer">{{ __('Redeploy') }}</span>
                    <span wire:loading wire:target="redeployContainer">{{ __('Queueing…') }}</span>
                </button>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Update the tag and click Redeploy to roll a new revision. Leave the tag the same to just re-pull.') }}</p>
        </div>
    @endif

    @php
        $imageHistory = is_array($containerMeta['image_history'] ?? null) ? array_reverse($containerMeta['image_history']) : [];
    @endphp
    @if (! $isSourceMode && count($imageHistory) > 1)
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Image history') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Click Roll back to redeploy a previous image tag. The current tag is highlighted.') }}</p>
            <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($imageHistory as $entry)
                    @php
                        $isCurrent = ($entry['image'] ?? null) === $site->container_image;
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs {{ $isCurrent ? 'bg-emerald-50/40' : '' }}">
                        <div class="min-w-0">
                            <p class="break-all font-mono text-slate-900">{{ $entry['image'] ?? '—' }}</p>
                            @if (! empty($entry['deployed_at']))
                                <p class="mt-0.5 text-[10px] text-slate-500">{{ __('Deployed :at', ['at' => $entry['deployed_at']]) }}</p>
                            @endif
                        </div>
                        @if ($isCurrent)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('Current') }}</span>
                        @else
                            <button type="button" wire:click="rollbackContainerImage('{{ $entry['image'] }}')" wire:confirm="{{ __('Roll back to :img? The backend will redeploy with this tag.', ['img' => $entry['image']]) }}" class="text-xs font-medium text-sky-700 hover:text-sky-900">{{ __('Roll back') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Environment variables') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Edit and click Save & redeploy. The backend\'s spec is updated and a fresh roll is queued.') }}</p>
            </div>
            <button type="button" wire:click="saveContainerEnvAndRedeploy" wire:loading.attr="disabled" wire:target="saveContainerEnvAndRedeploy" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-900 disabled:opacity-50">
                <span wire:loading.remove wire:target="saveContainerEnvAndRedeploy">{{ __('Save & redeploy') }}</span>
                <span wire:loading wire:target="saveContainerEnvAndRedeploy">{{ __('Saving…') }}</span>
            </button>
        </div>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div>
                <label for="container_env_file_input" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Runtime') }}</label>
                <p class="mt-0.5 text-[11px] text-slate-500">{{ __('Available at run-time only.') }}</p>
                <textarea id="container_env_file_input" wire:model="container_env_file_input" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                <x-input-error :messages="$errors->get('container_env_file_input')" class="mt-2" />
            </div>
            <div>
                <label for="container_build_env_file_input" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Build-time') }}</label>
                <p class="mt-0.5 text-[11px] text-slate-500">{{ __('Available during build, hidden at runtime. Use for private package tokens.') }}</p>
                <textarea id="container_build_env_file_input" wire:model="container_build_env_file_input" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="NPM_TOKEN=ghp_xxx&#10;COMPOSER_AUTH={...}"></textarea>
                <x-input-error :messages="$errors->get('container_build_env_file_input')" class="mt-2" />
            </div>
        </div>
    </div>

    @if ($isSourceMode && empty($containerMeta['preview_parent_site_id']))
        @php
            $previews = \App\Actions\Edge\CreateEdgePreviewSite::listForParent($site);
        @endphp
        @if ($previews->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Preview deployments') }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('One preview per branch — typically driven by your CI on PR open / sync / close.') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-800">{{ $previews->count() }}</span>
                </div>
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($previews as $preview)
                        @php
                            $previewMeta = is_array($preview->meta['container'] ?? null) ? $preview->meta['container'] : [];
                            $previewBranch = $previewMeta['preview_branch'] ?? '—';
                            $previewPrNumber = $previewMeta['preview_pr_number'] ?? null;
                            $previewLiveUrl = $preview->containerLiveUrl();
                            $previewStatusClass = match ($preview->status) {
                                \App\Models\Site::STATUS_CONTAINER_ACTIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\Site::STATUS_CONTAINER_PROVISIONING => 'bg-sky-100 text-sky-800',
                                \App\Models\Site::STATUS_CONTAINER_FAILED => 'bg-rose-100 text-rose-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-xs">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-slate-900">
                                    @if ($previewPrNumber)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">PR #{{ $previewPrNumber }}</span>
                                    @endif
                                    {{ $previewBranch }}
                                </p>
                                @if ($previewLiveUrl)
                                    <a href="{{ $previewLiveUrl }}" target="_blank" rel="noopener" class="break-all text-sky-700 hover:underline">{{ $previewLiveUrl }}</a>
                                @else
                                    <span class="text-slate-500">{{ __('No live URL yet') }}</span>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $previewStatusClass }}">
                                    {{ str_replace('_', ' ', (string) $preview->status) }}
                                </span>
                                <button type="button"
                                    wire:click="tearDownContainerPreview('{{ $preview->id }}')"
                                    wire:confirm="{{ __('Tear down preview for branch :branch?', ['branch' => $previewBranch]) }}"
                                    class="text-[11px] font-medium text-rose-700 hover:text-rose-900">
                                    {{ __('Tear down') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @php
        $activity = \App\Support\Edge\ContainerActivityTimeline::for($site);
    @endphp
    @if ($activity !== [])
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Recent activity') }}</p>
            <ol class="mt-3 space-y-2">
                @foreach (array_slice($activity, 0, 8) as $event)
                    <li class="flex items-start gap-3 text-xs">
                        <span class="mt-0.5 inline-flex size-2 shrink-0 rounded-full
                            {{ $event['kind'] === 'error' || $event['kind'] === 'poll_error' ? 'bg-rose-500' : '' }}
                            {{ $event['kind'] === 'provisioned' ? 'bg-emerald-500' : '' }}
                            {{ $event['kind'] === 'deploy' ? 'bg-sky-500' : '' }}
                            {{ $event['kind'] === 'domain_attached' ? 'bg-indigo-500' : '' }}
                            {{ $event['kind'] === 'teardown' ? 'bg-slate-400' : '' }}
                            {{ $event['kind'] === 'poll' ? 'bg-slate-300' : '' }}
                        "></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-slate-900">{{ __($event['label']) }}</p>
                            @if ($event['detail'])
                                <p class="mt-0.5 break-all text-slate-600">{{ $event['detail'] }}</p>
                            @endif
                        </div>
                        @if ($event['at'])
                            <span class="shrink-0 font-mono text-[10px] text-slate-500" title="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans(null, true) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @php
        $attachedDomains = is_array($containerMeta['domains'] ?? null) ? $containerMeta['domains'] : [];
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Custom domains') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Point your own hostnames at the backend\'s default ingress. Validation records (if any) appear after the attach completes.') }}</p>
        </div>

        @if ($attachedDomains !== [])
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($attachedDomains as $hostname => $info)
                    <li class="px-3 py-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-slate-900 break-all">{{ $hostname }}</span>
                            <button type="button" wire:click="detachContainerDomain('{{ $hostname }}')" wire:confirm="{{ __('Remove :host from this app?', ['host' => $hostname]) }}" class="text-xs font-medium text-rose-700 hover:text-rose-900">{{ __('Remove') }}</button>
                        </div>
                        @if (! empty($info['validation_records']))
                            <div class="mt-2 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-700">
                                <p class="font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('DNS validation records') }}</p>
                                @foreach ($info['validation_records'] as $rec)
                                    <p class="font-mono break-all">{{ $rec['type'] }} <span class="text-slate-500">→</span> {{ $rec['name'] }} <span class="text-slate-500">⇒</span> {{ $rec['value'] }}</p>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-xs text-slate-500">{{ __('No custom domains attached yet — the app is reachable at its default backend URL.') }}</p>
        @endif

        <div class="flex flex-col gap-2 sm:flex-row">
            <input type="text" wire:model="container_domain_input" class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="api.example.com" />
            <button type="button" wire:click="attachContainerDomain" wire:loading.attr="disabled" wire:target="attachContainerDomain" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="attachContainerDomain">{{ __('Attach domain') }}</span>
                <span wire:loading wire:target="attachContainerDomain">{{ __('Queueing…') }}</span>
            </button>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Deploy webhook') }}</p>
        <p class="text-xs text-slate-500">{{ __('POST to this URL from your CI to redeploy. Optional JSON body { "image": "ghcr.io/me/api:v2" } bumps the tag.') }}</p>
        <input type="text" readonly value="{{ $site->edgeRedeployHookUrl() }}" class="block w-full select-all rounded-md border-slate-300 bg-slate-50 font-mono text-xs text-slate-800 shadow-sm" onclick="this.select()" />
    </div>

    <div class="flex justify-end border-t border-slate-200 pt-4">
        <button type="button" wire:click="tearDownContainer" wire:confirm="{{ __('Permanently delete the container deployment? The backend resource will be torn down.') }}" class="text-sm font-medium text-rose-700 hover:text-rose-900">
            {{ __('Tear down container') }}
        </button>
    </div>
</section>
