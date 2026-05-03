@php
    $containerMeta = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
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
            <p class="mt-1 text-sm text-slate-600">{{ __('This site runs as a container on a managed backend. Roll out a new image tag or tear it down here.') }}</p>
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

    @if (! empty($containerMeta['last_deploy_started_at']))
        <p class="text-xs text-slate-500">{{ __('Last deploy started at :at', ['at' => $containerMeta['last_deploy_started_at']]) }}</p>
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

    <div class="flex justify-end border-t border-slate-200 pt-4">
        <button type="button" wire:click="tearDownContainer" wire:confirm="{{ __('Permanently delete the container deployment? The backend resource will be torn down.') }}" class="text-sm font-medium text-rose-700 hover:text-rose-900">
            {{ __('Tear down container') }}
        </button>
    </div>
</section>
