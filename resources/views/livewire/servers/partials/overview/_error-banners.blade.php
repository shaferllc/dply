{{-- Provisioning error banner --}}
@if ($provisionError && $server->status === \App\Models\Server::STATUS_ERROR)
    <section data-testid="server-provision-error" class="dply-card overflow-hidden border-rose-200">
        <div class="border-b border-brand-ink/10 bg-rose-50/70 px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['rose'] }}">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Provisioning error') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ __('Provisioning failed at :provider', ['provider' => $provisionError['provider'] ?? 'the provider']) }}
                    </h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $provisionError['message'] ?? __('Unknown error.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-moss">
                        @if (! empty($provisionError['region']))
                            <span><strong class="text-brand-ink">{{ __('Region') }}:</strong> {{ $provisionError['region'] }}</span>
                        @endif
                        @if (! empty($provisionError['size']))
                            <span><strong class="text-brand-ink">{{ __('Size') }}:</strong> {{ $provisionError['size'] }}</span>
                        @endif
                        @if (! empty($provisionError['at']))
                            <span><strong class="text-brand-ink">{{ __('At') }}:</strong> {{ $provisionError['at'] }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endif

{{-- K8s cluster gone away --}}
@if (! empty($kubernetesError))
    <section data-testid="kubernetes-cluster-error" class="dply-card overflow-hidden border-rose-200">
        <div class="border-b border-brand-ink/10 bg-rose-50/70 px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['rose'] }}">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Cluster unavailable') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                        {{ __(':provider can\'t find this cluster anymore', ['provider' => $kubernetesError['provider_label']]) }}
                    </h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $kubernetesError['message'] }}</p>
                </div>
            </div>
        </div>
        <div class="px-6 py-5 sm:px-7">
            <dl class="grid gap-3 text-xs sm:grid-cols-2">
                @if ($kubernetesError['cluster_name'] !== '')
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster name') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $kubernetesError['cluster_name'] }}</dd>
                    </div>
                @endif
                @if ($kubernetesError['cluster_id'] !== '')
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster id') }}</dt>
                        <dd class="mt-1 break-all font-mono text-brand-ink">{{ $kubernetesError['cluster_id'] }}</dd>
                    </div>
                @endif
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="retryClusterPolling"
                    wire:loading.attr="disabled"
                    wire:target="retryClusterPolling"
                    class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path wire:loading.remove wire:target="retryClusterPolling" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <x-spinner wire:loading wire:target="retryClusterPolling" variant="white" size="sm" />
                    {{ __('Re-check now') }}
                </button>
                @feature('workspace.cluster')
                    <a href="{{ route('servers.cluster', $server) }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Open cluster page') }}
                    </a>
                @endfeature
                <a href="{{ $kubernetesError['provider_console_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open in :provider', ['provider' => $kubernetesError['provider_label']]) }}
                    <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
