@php
    $inflightEdge = $this->hasInflightEdgeProxyAction();
    $inflightSwitch = $this->hasInflightWebserverSwitch();
@endphp

<div class="{{ $card }}">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-arrow-path-rounded-square"
        :title="__('Edge proxy')"
        :count="$activeEdgeProxy !== null ? __(':name active', ['name' => $edgeProxyCatalog[$activeEdgeProxy]['label']]) : null"
        :note="__('Optional L7 reverse proxy in front of your webserver. Caddy serves each site on an ephemeral high port; the edge proxy routes hosts to those backends on :80. Switch between Traefik and HAProxy without removing the active one first.', ['port' => 80])"
        class="border-b border-brand-ink/10"
    />

    @if ($inflightEdge)
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
            {{ __('An edge proxy action is currently running. Buttons are disabled until it settles — watch the progress banner at the top of this page.') }}
        </p>
    @elseif ($inflightSwitch)
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 border-b border-amber-200/80 bg-amber-50/60 px-4 py-2 text-[11px] text-amber-900 sm:px-5">
            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
            {{ __('A webserver switch is currently running. Wait for it to finish before changing the edge proxy.') }}
        </p>
    @endif

    <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
        @foreach ($edgeProxyCatalog as $key => $info)
            @php
                $isActiveEdge = $key === $activeEdgeProxy;
                $isComingSoon = ! $isActiveEdge && ! empty($info['coming_soon']);
            @endphp
            <div @class([
                'rounded-xl border bg-white p-3',
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
