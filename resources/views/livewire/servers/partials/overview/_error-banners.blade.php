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
        <div class="min-w-0 overflow-hidden bg-rose-50/70 px-3 py-2 sm:px-4">
            <div class="min-w-0">
                    @php($provisionErrorMessage = $provisionError['message'] ?? __('Unknown error.'))
                    {{-- Provider errors arrive as one unbroken string often enough
                         that without an explicit wrap they push the card past the
                         viewport. Clamped to two lines with the full text one
                         click away. --}}
                    <div class="flex min-w-0 items-start gap-2" x-data="{ expanded: false, copied: false }">
                        <p
                            class="min-w-0 flex-1 break-words text-xs leading-relaxed text-brand-moss"
                            :class="expanded ? '' : 'line-clamp-2'"
                        >{{ $provisionErrorMessage }}</p>
                        <div class="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                x-on:click="expanded = ! expanded"
                                class="rounded p-0.5 text-brand-mist hover:bg-rose-100 hover:text-brand-ink"
                                :title="expanded ? '{{ __('Show less') }}' : '{{ __('Show full error') }}'"
                            >
                                <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition" ::class="expanded && 'rotate-180'" />
                            </button>
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText(@js($provisionErrorMessage)); copied = true; setTimeout(() => copied = false, 1500)"
                                class="rounded p-0.5 text-brand-mist hover:bg-rose-100 hover:text-brand-ink"
                                :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy error') }}'"
                            >
                                <x-heroicon-o-clipboard class="h-3.5 w-3.5" x-show="!copied" />
                                <x-heroicon-o-check class="h-3.5 w-3.5 text-brand-forest" x-show="copied" x-cloak />
                            </button>
                        </div>
                    </div>
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
