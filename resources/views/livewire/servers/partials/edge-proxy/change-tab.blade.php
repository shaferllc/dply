<div class="{{ $card }} p-6 sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-2xl">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge proxy') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on an ephemeral high port; the edge proxy routes hosts to those backends on :80. Switch between Traefik and HAProxy without removing the active one first.', ['port' => 80]) }}
            </p>
        </div>
        @if ($activeEdgeProxy !== null)
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[11px] font-medium text-brand-forest">
                <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                {{ $edgeProxyCatalog[$activeEdgeProxy]['label'] }} {{ __('active') }}
            </span>
        @endif
    </div>

    @php
        $inflightEdge = $this->hasInflightEdgeProxyAction();
        $inflightSwitch = $this->hasInflightWebserverSwitch();
    @endphp
    @if ($inflightEdge)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
            {{ __('An edge proxy action is currently running. Buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
        </div>
    @elseif ($inflightSwitch)
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
            {{ __('A webserver switch is currently running. Wait for it to finish before changing the edge proxy.') }}
        </div>
    @endif

    <div class="mt-5 grid gap-3 sm:grid-cols-2">
        @foreach ($edgeProxyCatalog as $key => $info)
            @php
                $isActiveEdge = $key === $activeEdgeProxy;
                $isComingSoon = ! $isActiveEdge && ! empty($info['coming_soon']);
            @endphp
            <div @class([
                'rounded-xl border bg-white p-4',
                'border-brand-forest/30 ring-1 ring-brand-forest/20' => $isActiveEdge,
                'border-brand-ink/10' => ! $isActiveEdge,
            ])>
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-start gap-2">
                        <x-dynamic-component :component="$info['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-brand-forest" />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-brand-ink">{{ $info['label'] }}</p>
                            @if ($isActiveEdge)
                                <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('Routing traffic on :80', ['port' => 80]) }}</p>
                            @endif
                        </div>
                    </div>
                    @if ($isComingSoon)
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-brand-sand/70 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                            {{ __('Soon') }}
                        </span>
                    @endif
                </div>

                @include('livewire.servers.partials.webserver._edge-proxy-target-action', [
                    'actionInFlight' => $actionInFlight,
                    'inflightEdge' => $inflightEdge,
                    'inflightSwitch' => $inflightSwitch,
                    'edgeProxyActionTarget' => $edgeProxyActionTarget ?? null,
                ])
            </div>
        @endforeach
    </div>
</div>
