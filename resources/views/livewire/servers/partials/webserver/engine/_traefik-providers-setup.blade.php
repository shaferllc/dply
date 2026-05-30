@if ($key === 'traefik' && $isActive && $engineHasFullControls($key) && ($engine_subtab === 'providers' || ($optimisticEngineSubtabs ?? false)))
    <div @if ($optimisticEngineSubtabs ?? false) x-show="subtab === 'providers'" x-cloak @endif class="mb-6" wire:key="traefik-providers-setup">
        <div class="{{ $card }} p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Config providers') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm text-brand-moss">
                        {{ __('Enable Docker, Kubernetes, or Consul in traefik.yml. The file provider stays on for dply site routes. Saving restarts Traefik.') }}
                    </p>
                </div>
                <button type="button" wire:click="loadTraefikProvidersConfig" wire:loading.attr="disabled" wire:target="loadTraefikProvidersConfig"
                    class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                    <span wire:loading.remove wire:target="loadTraefikProvidersConfig"><x-heroicon-o-arrow-path class="h-3.5 w-3.5" /></span>
                    <span wire:loading wire:target="loadTraefikProvidersConfig"><x-spinner class="h-3.5 w-3.5" /></span>
                    {{ __('Reload') }}
                </button>
            </div>

            @if ($traefik_providers_flash)
                <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/70 px-4 py-2.5 text-sm text-emerald-900">{{ $traefik_providers_flash }}</div>
            @endif
            @if ($traefik_providers_error)
                <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50/70 px-4 py-2.5 text-sm text-rose-900">{{ $traefik_providers_error }}</div>
            @endif

            @if ($traefik_providers_loaded && $traefik_providers_configured !== [])
                <ul class="mt-4 flex flex-wrap gap-2">
                    @foreach ($traefik_providers_configured as $prov)
                        <li class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/50 px-2.5 py-1 text-[11px] font-medium text-brand-ink ring-1 ring-brand-ink/10">
                            <span class="font-semibold">{{ $prov['label'] }}</span>
                            <span class="text-brand-moss">{{ $prov['summary'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($traefik_providers_loaded)
                <form wire:submit.prevent="saveTraefikProvidersConfig" class="mt-6 space-y-6">
                    <fieldset class="rounded-xl border border-brand-ink/10 p-4">
                        <legend class="px-1 text-sm font-semibold text-brand-ink">{{ __('Docker') }}</legend>
                        <label class="mt-2 inline-flex items-center gap-2">
                            <input type="checkbox" value="1" wire:model.live="traefik_providers_form.docker_enabled" class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest" />
                            <span class="text-sm text-brand-moss">{{ __('Enable Docker provider') }}</span>
                        </label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-medium text-brand-ink">{{ __('Endpoint') }}</span>
                                <input type="text" wire:model.lazy="traefik_providers_form.docker_endpoint" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" />
                            </label>
                            <label class="mt-6 inline-flex items-center gap-2 sm:mt-8">
                                <input type="checkbox" value="1" wire:model.live="traefik_providers_form.docker_exposedByDefault" class="h-4 w-4 rounded" />
                                <span class="text-xs text-brand-moss">{{ __('exposedByDefault') }}</span>
                            </label>
                        </div>
                        <button type="button" wire:click="installTraefikDockerProvider" wire:loading.attr="disabled" wire:target="installTraefikDockerProvider"
                            class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium hover:bg-brand-sand/40">
                            {{ __('Install Docker on server') }}
                        </button>
                    </fieldset>

                    <fieldset class="rounded-xl border border-brand-ink/10 p-4">
                        <legend class="px-1 text-sm font-semibold text-brand-ink">{{ __('Kubernetes') }}</legend>
                        <label class="mt-2 inline-flex items-center gap-2">
                            <input type="checkbox" value="1" wire:model.live="traefik_providers_form.k8s_enabled" class="h-4 w-4 rounded" />
                            <span class="text-sm text-brand-moss">{{ __('Enable Kubernetes provider') }}</span>
                        </label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block sm:col-span-2">
                                <span class="text-xs font-medium">{{ __('Kubeconfig path') }}</span>
                                <input type="text" wire:model.lazy="traefik_providers_form.k8s_kubeconfig" placeholder="/root/.kube/config" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm" />
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" value="1" wire:model.live="traefik_providers_form.k8s_inCluster" class="h-4 w-4 rounded" />
                                <span class="text-xs text-brand-moss">{{ __('inCluster (when Traefik runs inside the cluster)') }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="rounded-xl border border-brand-ink/10 p-4">
                        <legend class="px-1 text-sm font-semibold text-brand-ink">{{ __('Consul') }}</legend>
                        <label class="mt-2 inline-flex items-center gap-2">
                            <input type="checkbox" value="1" wire:model.live="traefik_providers_form.consul_enabled" class="h-4 w-4 rounded" />
                            <span class="text-sm text-brand-moss">{{ __('Enable Consul provider') }}</span>
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-medium">{{ __('Consul endpoint') }}</span>
                            <input type="text" wire:model.lazy="traefik_providers_form.consul_endpoint" class="mt-1 block w-full max-w-md rounded-md border-brand-ink/15 font-mono text-sm" />
                        </label>
                    </fieldset>

                    <div class="flex justify-end">
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveTraefikProvidersConfig"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-semibold text-brand-cream disabled:opacity-60">
                            {{ __('Save providers and restart Traefik') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endif
