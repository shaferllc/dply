{{-- Provisioning error banner --}}
@if ($provisionError && $server->status === \App\Models\Server::STATUS_ERROR)
    <section data-testid="server-provision-error" class="dply-card overflow-hidden border-rose-200 p-0">
        <x-workspace-panel-head
            dense
            tone="danger"
            icon="heroicon-o-exclamation-triangle"
            :title="__('Provisioning failed at :provider', ['provider' => $provisionError['provider'] ?? 'the provider'])"
            class="border-b border-brand-ink/10"
        />
        <div class="bg-rose-50/70 px-3 py-2 sm:px-4">
            <div class="min-w-0">
                    <p class="text-xs leading-relaxed text-brand-moss">{{ $provisionError['message'] ?? __('Unknown error.') }}</p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-moss">
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
    </section>
@endif

{{-- K8s cluster gone away --}}
@if (! empty($kubernetesError))
    <section data-testid="kubernetes-cluster-error" class="dply-card overflow-hidden border-rose-200 p-0">
        <x-workspace-panel-head
            dense
            tone="danger"
            icon="heroicon-o-exclamation-triangle"
            :title="__(':provider can\'t find this cluster anymore', ['provider' => $kubernetesError['provider_label']])"
            class="border-b border-brand-ink/10"
        />
        <p class="bg-rose-50/70 px-3 py-2 text-xs leading-relaxed text-brand-moss sm:px-4">{{ $kubernetesError['message'] }}</p>
        <div class="border-t border-brand-ink/10 px-3 py-3 sm:px-4">
            <dl class="grid gap-3 text-xs sm:grid-cols-2">
                @if ($kubernetesError['cluster_name'] !== '')
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster name') }}</dt>
                        <dd class="mt-1 font-mono text-brand-ink">{{ $kubernetesError['cluster_name'] }}</dd>
                    </div>
                @endif
                @if ($kubernetesError['cluster_id'] !== '')
                    <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cluster id') }}</dt>
                        <dd class="mt-1 break-all font-mono text-brand-ink">{{ $kubernetesError['cluster_id'] }}</dd>
                    </div>
                @endif
            </dl>
            <div class="mt-3 flex flex-wrap gap-1.5">
                <button
                    type="button"
                    wire:click="retryClusterPolling"
                    wire:loading.attr="disabled"
                    wire:target="retryClusterPolling"
                    class="inline-flex h-6 items-center justify-center gap-1 whitespace-nowrap rounded-lg bg-rose-600 px-2.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-arrow-path wire:loading.remove wire:target="retryClusterPolling" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <x-spinner wire:loading wire:target="retryClusterPolling" variant="white" size="sm" />
                    {{ __('Re-check now') }}
                </button>
                @feature('workspace.cluster')
                    <a href="{{ route('servers.cluster', $server) }}" wire:navigate class="inline-flex h-6 items-center justify-center gap-1 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Open cluster page') }}
                    </a>
                @endfeature
                <a href="{{ $kubernetesError['provider_console_url'] }}" target="_blank" rel="noopener" class="inline-flex h-6 items-center justify-center gap-1 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    {{ __('Open in :provider', ['provider' => $kubernetesError['provider_label']]) }}
                    <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                </a>
            </div>
        </div>
    </section>
@endif
