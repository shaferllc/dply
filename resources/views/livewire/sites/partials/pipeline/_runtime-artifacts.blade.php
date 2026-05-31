@if ($site->usesDockerRuntime())
    @php
        $dockerRuntime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
    @endphp
    <details class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
        <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Runtime target') }} <span class="font-normal text-brand-moss">— {{ __('Compose / Dockerfile (`docker compose up -d --build` on deploy)') }}</span></summary>
        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Compose file') }}</p>
                <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-sky-100">{{ $dockerRuntime['compose_yaml'] ?? __('Not generated yet') }}</pre>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Managed Dockerfile') }}</p>
                <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-emerald-100">{{ $dockerRuntime['dockerfile'] ?? __('Not generated yet') }}</pre>
            </div>
        </div>
    </details>
@endif

@if ($site->usesKubernetesRuntime())
    @php
        $kubernetesRuntime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];
    @endphp
    <details class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4">
        <summary class="cursor-pointer list-none text-sm font-semibold text-brand-ink">{{ __('Runtime target') }} <span class="font-normal text-brand-moss">— {{ __('Manifest for namespace') }} <code>{{ $kubernetesRuntime['namespace'] ?? 'default' }}</code></span></summary>
        <div class="mt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Manifest') }}</p>
            <pre class="mt-2 max-h-96 overflow-auto rounded-xl bg-brand-ink p-3 text-xs text-violet-100">{{ $kubernetesRuntime['manifest_yaml'] ?? __('Not generated yet') }}</pre>
        </div>
    </details>
@endif
