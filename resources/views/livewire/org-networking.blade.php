@php
    $lbCount = $loadBalancers->count();
    $networkCount = $networks->count();
    $hasInventory = $lbCount > 0 || $networkCount > 0;
    $onLoadBalancers = $tab === 'load-balancers';
    $onNetworks = $tab === 'networks';
    $showLbShellActions = $onLoadBalancers && $lbCount > 0;
    $showNetworkShellActions = $onNetworks && $networkCount > 0;
    // Full-size buttons stay for the empty states (they're the page's only call
    // to action there); the head rides the dense 24px pill used workspace-wide.
    $headerBtn = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold shadow-sm transition-colors';
    $headBtn = 'inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-2 text-xs font-semibold shadow-sm transition';
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail
            doc-route="docs.index"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Networking'), 'icon' => 'share'],
            ]"
        />

        {{-- Dense head + a two-cell figure strip: the icon-badge stack and the
             pair of bordered count tiles cost ~150px above the tab strip to
             carry two numbers the tabs already badge. --}}
        <x-profile-shell
            dense
            :title="__('Networking')"
            :description="__('Load balancers, private networks, and routes across your workspace.')"
            icon="heroicon-o-share"
        >
            @if ($showLbShellActions)
                <x-slot:actions>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'org-create-haproxy-lb-modal')"
                        class="{{ $headBtn }} bg-brand-ink text-brand-cream hover:bg-brand-forest"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Software (HAProxy)') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'org-create-hetzner-lb-modal')"
                        class="{{ $headBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Managed (Hetzner)') }}
                    </button>
                </x-slot:actions>
            @elseif ($showNetworkShellActions)
                <x-slot:actions>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'create-network-modal')"
                        class="{{ $headBtn }} bg-brand-ink text-brand-cream hover:bg-brand-forest"
                    >
                        <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                        {{ __('Create network') }}
                    </button>
                </x-slot:actions>
            @endif

            @if ($hasInventory)
                <x-slot:stats>
                    <x-workspace-stat-strip
                        :columns="2"
                        :stats="[
                            ['label' => __('Load balancers'), 'value' => $lbCount],
                            ['label' => __('Networks'), 'value' => $networkCount],
                        ]"
                    />
                </x-slot:stats>
            @endif

            <x-slot:tabs>
                <x-server-workspace-tablist :aria-label="__('Networking sections')" bare class="!mb-0 w-full">
                    <x-server-workspace-tab
                        id="net-tab-lb"
                        icon="heroicon-o-arrows-right-left"
                        :active="$onLoadBalancers"
                        wire:click="setTab('load-balancers')"
                    >
                        {{ __('Load balancers') }}
                        @if ($lbCount > 0)
                            <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $lbCount }}</span>
                        @endif
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="net-tab-networks"
                        icon="heroicon-o-share"
                        :active="$onNetworks"
                        wire:click="setTab('networks')"
                    >
                        {{ __('Networks') }}
                        @if ($networkCount > 0)
                            <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $networkCount }}</span>
                        @endif
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>
            </x-slot:tabs>

            {{-- ═══════════════════════════════════════════════════════════════════ --}}
            {{-- LOAD BALANCERS TAB                                                  --}}
            {{-- ═══════════════════════════════════════════════════════════════════ --}}
            @if ($onLoadBalancers)
                @if ($loadBalancers->isEmpty())
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-arrows-right-left"
                        :title="__('No load balancers yet')"
                        :description="__('Software (HAProxy) LBs run on any server you already own — free. Managed Hetzner LBs are fully redundant but cost extra.')"
                    >
                        <x-slot:actions>
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'org-create-haproxy-lb-modal')"
                                class="{{ $headerBtn }} bg-brand-ink text-brand-cream shadow-md hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Software (HAProxy)') }}
                            </button>
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'org-create-hetzner-lb-modal')"
                                class="{{ $headerBtn }} border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Managed (Hetzner)') }}
                            </button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    {{-- One line per LB: name + kind badge, then addresses and
                         region as the muted remainder, status and actions right. --}}
                    <div class="divide-y divide-brand-ink/10">
                        @foreach ($loadBalancers as $lb)
                            @php
                                $pill = match ($lb->status) {
                                    'running'      => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'label' => __('Running')],
                                    'provisioning' => ['dot' => 'bg-sky-400',     'text' => 'text-sky-700',     'label' => __('Provisioning')],
                                    default        => ['dot' => 'bg-rose-500',    'text' => 'text-rose-700',    'label' => ucfirst($lb->status)],
                                };
                            @endphp
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 sm:px-5" wire:key="lb-{{ $lb->id }}">
                                <p class="shrink-0 text-xs font-semibold text-brand-ink">{{ $lb->name }}</p>
                                @if ($lb->isSoftware())
                                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">HAProxy · {{ $lb->server?->name }}</span>
                                @else
                                    <span class="shrink-0 rounded-full bg-sky-50 px-1.5 py-0.5 text-2xs font-semibold text-sky-700 ring-1 ring-sky-200">Hetzner</span>
                                @endif
                                <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-xs text-brand-mist">
                                    @if ($lb->public_ipv4) <span>{{ $lb->public_ipv4 }}</span> @endif
                                    @if ($lb->private_ip)  <span class="flex items-center gap-1"><x-heroicon-m-lock-closed class="h-2.5 w-2.5 shrink-0 text-emerald-500"/>{{ $lb->private_ip }}</span> @endif
                                    <span>{{ strtoupper($lb->load_balancer_type) }} · {{ $lb->region }}</span>
                                </div>
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-white px-1.5 py-0.5 text-2xs font-semibold ring-1 ring-brand-ink/10 {{ $pill['text'] }}">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                                    {{ $pill['label'] }}
                                </span>
                                <span class="shrink-0 text-xs tabular-nums text-brand-mist">{{ $lb->targets->count() }} {{ __('targets') }}</span>
                                <button type="button"
                                    wire:click="deleteLoadBalancer('{{ $lb->id }}')"
                                    class="inline-flex h-6 shrink-0 items-center rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif

            {{-- ═══════════════════════════════════════════════════════════════════ --}}
            {{-- NETWORKS TAB                                                         --}}
            {{-- ═══════════════════════════════════════════════════════════════════ --}}
            @if ($onNetworks)
                @if ($networks->isEmpty())
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-share"
                        :title="__('No private networks yet')"
                        :description="__('Create a private network to let your servers communicate on private IPs — keeping database, cache, and app traffic off the public internet.')"
                    >
                        <x-slot:actions>
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'create-network-modal')"
                                class="{{ $headerBtn }} bg-brand-ink text-brand-cream shadow-md hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create network') }}
                            </button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <div class="divide-y divide-brand-ink/10">
                        @foreach ($networks as $network)
                            @php $routes = $routesByNetwork[$network->id] ?? []; @endphp
                            <div wire:key="net-{{ $network->id }}">
                                {{-- Network header: name + range on one line, the
                                     provider/ID/count line folded in beside it. --}}
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-brand-sand/20 px-4 py-2 sm:px-5">
                                    <p class="shrink-0 text-xs font-semibold text-brand-ink">{{ $network->name }}</p>
                                    <code class="shrink-0 rounded bg-white px-1.5 py-0.5 font-mono text-2xs font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $network->ip_range }}</code>
                                    <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
                                    <p class="min-w-0 flex-1 truncate text-xs text-brand-mist">
                                        {{ ucfirst($network->provider) }} · ID {{ $network->provider_id ?? '—' }} · {{ trans_choice(':count server|:count servers', $network->servers->count(), ['count' => $network->servers->count()]) }}
                                    </p>
                                    <button type="button"
                                        wire:click="deleteNetwork('{{ $network->id }}')"
                                        class="inline-flex h-6 shrink-0 items-center rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">
                                        {{ __('Delete') }}
                                    </button>
                                </div>

                                {{-- Attached servers — chips rather than a grid of
                                     bordered tiles; the pair of facts per server
                                     fits on one line. --}}
                                @if ($network->servers->isNotEmpty())
                                    <div class="flex flex-wrap items-center gap-1.5 border-t border-brand-ink/5 px-4 py-2 sm:px-5">
                                        @foreach ($network->servers as $s)
                                            <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white px-2 py-0.5 text-xs">
                                                <span class="font-semibold text-brand-ink">{{ $s->name }}</span>
                                                <span class="font-mono text-2xs text-brand-mist">{{ $s->private_ip_address ?? __('pending…') }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Add a server to this network — gives it a private
                                     IP here so it can reach the other members. --}}
                                @if ($network->isHetzner())
                                    @php $attachable = $this->attachableServers($network); @endphp
                                    {{-- Attach control as an inline strip; the
                                         "gives the server a private IP" line is
                                         the label's title, not a third row. --}}
                                    <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/5 px-4 py-2 sm:px-5">
                                        @if ($attachable->isEmpty())
                                            <p class="text-xs text-brand-mist">{{ __('All your Hetzner servers are already on this network.') }}</p>
                                        @else
                                            <label
                                                for="attach-server-{{ $network->id }}"
                                                class="shrink-0 text-2xs font-semibold uppercase tracking-wide text-brand-mist"
                                                title="{{ __('Gives the server a private IP on this network so it can reach the others (database, cache, app).') }}"
                                            >{{ __('Add a server') }}</label>
                                            <select
                                                id="attach-server-{{ $network->id }}"
                                                wire:model="attach_server_id.{{ $network->id }}"
                                                class="min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-forest/30 sm:max-w-xs"
                                            >
                                                <option value="">{{ __('Choose a server…') }}</option>
                                                @foreach ($attachable as $cand)
                                                    <option value="{{ $cand->id }}">{{ $cand->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="addServerToNetwork('{{ $network->id }}')" wire:loading.attr="disabled" wire:target="addServerToNetwork('{{ $network->id }}')" class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest disabled:opacity-60">
                                                <x-heroicon-m-plus class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('Attach server') }}
                                            </button>
                                            <x-input-error :messages="$errors->get('attach_server_id.'.$network->id)" class="w-full" />
                                        @endif
                                    </div>
                                @endif

                                {{-- Routes --}}
                                @if ($network->isHetzner())
                                    @php $gatewayServers = $network->servers->whereNotNull('private_ip_address'); @endphp
                                    <div class="border-t border-brand-ink/5 px-4 py-2 sm:px-5">
                                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Routes') }}</p>
                                        @if (! empty($routes))
                                            <div class="mt-1.5 space-y-1">
                                                @foreach ($routes as $route)
                                                    @php $gwServer = $orgServers->firstWhere('private_ip_address', $route['gateway']); @endphp
                                                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded-md border border-brand-ink/10 bg-white px-2 py-1 text-xs text-brand-ink">
                                                        <span class="text-brand-mist">{{ __('Traffic for') }}</span>
                                                        <code class="font-mono font-semibold text-brand-ink">{{ $route['destination'] }}</code>
                                                        <span class="text-brand-mist">{{ __('goes through') }}</span>
                                                        <span class="font-semibold text-brand-ink">{{ $gwServer?->name ?? $route['gateway'] }}</span>
                                                        <span class="font-mono text-2xs text-brand-mist">{{ $route['gateway'] }}</span>
                                                        <button type="button"
                                                            wire:click="deleteRoute('{{ $network->id }}', '{{ $route['destination'] }}', '{{ $route['gateway'] }}')"
                                                            class="ml-auto shrink-0 font-semibold text-rose-600 hover:underline">
                                                            {{ __('Remove') }}
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($gatewayServers->isEmpty())
                                            <p class="mt-1.5 text-xs text-brand-mist">{{ __('Routes need at least one server on this network with a private IP to act as the gateway.') }}</p>
                                        @else
                                            {{-- Add route — intent-first --}}
                                            <div
                                                x-data="{
                                                    get dest() { return $wire.get('route_destination.{{ $network->id }}') || '' },
                                                    get gw()   { return $wire.get('route_gateway_server.{{ $network->id }}') || '' },
                                                    gwName(id) { return ({@foreach ($gatewayServers as $s)'{{ $s->id }}':@js($s->name),@endforeach})[id] || '' },
                                                    get cidrOk() { return /^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/.test(this.dest.trim()) },
                                                    preset(v) { $wire.set('route_destination.{{ $network->id }}', v) },
                                                }"
                                                class="mt-1.5 space-y-1.5"
                                            >
                                                {{-- The sentence-framed row keeps its shape — it's
                                                     what makes a route readable — on one line, with
                                                     the presets riding the same row and the labels
                                                     inline instead of stacked above each field. --}}
                                                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                                    <label for="route-dest-{{ $network->id }}" class="shrink-0 text-xs text-brand-mist">{{ __('Send traffic for') }}</label>
                                                    <x-text-input id="route-dest-{{ $network->id }}" wire:model.live="route_destination.{{ $network->id }}" class="!h-7 w-36 !px-2 !py-0 font-mono !text-xs" placeholder="192.168.1.0/24" />
                                                    <span class="shrink-0 text-xs text-brand-mist">{{ __('through') }}</span>
                                                    <select wire:model="route_gateway_server.{{ $network->id }}" class="min-w-0 flex-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink shadow-sm focus:border-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-forest/30 sm:max-w-xs">
                                                        <option value="">{{ __('Choose a server…') }}</option>
                                                        @foreach ($gatewayServers as $s)
                                                            <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->private_ip_address }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="button" wire:click="addRoute('{{ $network->id }}')"
                                                        wire:loading.attr="disabled" wire:target="addRoute('{{ $network->id }}')"
                                                        class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md bg-brand-forest px-2 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-forest/90 disabled:opacity-50">
                                                        <span wire:loading.remove wire:target="addRoute('{{ $network->id }}')">{{ __('Add route') }}</span>
                                                        <span wire:loading wire:target="addRoute('{{ $network->id }}')" class="inline-flex items-center gap-1"><x-spinner variant="white" size="sm"/>{{ __('Adding…') }}</span>
                                                    </button>
                                                </div>

                                                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Common:') }}</span>
                                                    <button type="button" x-on:click="preset('10.8.0.0/24')"
                                                        class="rounded-full border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                                                        {{ __('WireGuard VPN clients') }}
                                                    </button>
                                                    <button type="button" x-on:click="preset('')"
                                                        class="rounded-full border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold text-brand-ink transition hover:bg-brand-sand/40">
                                                        {{ __('Custom range') }}
                                                    </button>
                                                    @if (empty($routes))
                                                        <span class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Add a route if you need a server to forward traffic for another range — for example a WireGuard VPN gateway.') }}">
                                                            {{ __('Add a route if you need a server to forward traffic for another range — for example a WireGuard VPN gateway.') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                {{-- Live plain-language explanation --}}
                                                <p x-show="cidrOk && gw" x-cloak class="text-xs text-brand-moss">
                                                    {{ __('Any server on this network trying to reach') }}
                                                    <code class="font-mono" x-text="dest.trim()"></code>
                                                    {{ __('will send it to') }}
                                                    <span class="font-medium" x-text="gwName(gw)"></span>,
                                                    {{ __('which forwards it on.') }}
                                                </p>
                                                <p x-show="dest.trim() && ! cidrOk" x-cloak class="text-xs text-rose-700">
                                                    {{ __("That doesn't look like a valid range — try 192.168.1.0/24.") }}
                                                </p>

                                                @error("route_destination.$network->id") <p class="text-xs text-rose-700">{{ $message }}</p> @enderror
                                                @error("route_gateway_server.$network->id") <p class="text-xs text-rose-700">{{ $message }}</p> @enderror
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-profile-shell>

        {{-- Create network modal --}}
        <x-modal name="create-network-modal" max-width="lg" focusable>
            <div class="bg-white">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-share class="h-5 w-5" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create private network') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Dply creates the network in Hetzner and attaches the selected servers. Private IPs appear once assigned (~30 s).') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'create-network-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
                </div>
                <div class="space-y-5 p-6">
                    <div class="grid gap-5 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="net_name" :value="__('Network name')" />
                            <x-text-input id="net_name" wire:model="net_name" class="mt-1 block w-full" placeholder="e.g. dply-private" />
                            @error('net_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="net_ip_range" :value="__('IP range (CIDR)')" />
                            <x-text-input id="net_ip_range" wire:model="net_ip_range" class="mt-1 block w-full font-mono" placeholder="10.0.0.0/8" />
                            @error('net_ip_range') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <x-input-label for="net_credential_id" :value="__('Hetzner account')" />
                        <select id="net_credential_id" wire:model="net_credential_id" class="dply-input mt-1 block w-full">
                            <option value="">{{ __('Select a credential…') }}</option>
                            @foreach ($hetznerCredentials as $cred)
                                <option value="{{ $cred->id }}">{{ $cred->name }}</option>
                            @endforeach
                        </select>
                        @error('net_credential_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <p class="mb-2 text-sm font-medium text-brand-ink">{{ __('Attach these servers') }}</p>
                        <div class="space-y-2">
                            @foreach ($orgServers->where('provider.value', 'hetzner') as $s)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/10">
                                    <input type="checkbox" wire:model="net_server_ids" value="{{ $s->id }}" class="rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $s->name }}</p>
                                        <p class="font-mono text-xs text-brand-mist">{{ $s->ip_address }} · {{ $s->region }}</p>
                                    </div>
                                    @if ($s->private_ip_address)
                                        <span class="ml-auto text-xs text-emerald-600">{{ __('already on a network') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                    <button type="button" x-on:click="$dispatch('close-modal', 'create-network-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="createNetwork" wire:loading.attr="disabled" wire:target="createNetwork"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="createNetwork">{{ __('Create network') }}</span>
                        <span wire:loading wire:target="createNetwork" class="inline-flex items-center gap-2"><x-spinner variant="white" size="sm"/>{{ __('Creating…') }}</span>
                    </button>
                </div>
            </div>
        </x-modal>

        {{-- ─── CREATE SOFTWARE (HAProxy) LB MODAL ─────────────────────────────── --}}
        <x-modal name="org-create-haproxy-lb-modal" max-width="2xl" focusable>
            <div class="bg-white">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Free · HAProxy') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create software load balancer') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Runs HAProxy on a server you already own. No extra cost — just the server. Dply writes the config and reloads over SSH.') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'org-create-haproxy-lb-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-6 overflow-y-auto p-6">
                    <div class="grid gap-5 sm:grid-cols-2 sm:items-end">
                        <div>
                            <x-input-label for="haproxy_lb_name" :value="__('Name')" />
                            <x-text-input id="haproxy_lb_name" wire:model="lb_name" class="mt-1 block w-full" placeholder="e.g. web-lb" />
                            @error('lb_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="haproxy_lb_algorithm" :value="__('Algorithm')" />
                            <select id="haproxy_lb_algorithm" wire:model="lb_algorithm" class="dply-input mt-1 block w-full">
                                <option value="round_robin">{{ __('Round robin') }}</option>
                                <option value="least_connections">{{ __('Least connections') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="org_haproxy_server_id" :value="__('HAProxy server')" />
                        <p class="mt-0.5 text-xs text-brand-mist">{{ __('Pick any server with the "Load balancer" role (HAProxy pre-installed). Or create one from the server wizard first.') }}</p>
                        <select id="org_haproxy_server_id" wire:model="haproxy_server_id" class="dply-input mt-2 block w-full">
                            <option value="">{{ __('Select a server…') }}</option>
                            @foreach ($orgServers as $s)
                                @php $role = data_get($s->meta, 'server_role', ''); @endphp
                                <option value="{{ $s->id }}">
                                    {{ $s->name }}
                                    @if ($role === 'load_balancer') ({{ __('load balancer role') }}) @endif
                                    — {{ $s->ip_address }} · {{ $s->region }}
                                </option>
                            @endforeach
                        </select>
                        @error('haproxy_server_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    @include('livewire.servers.partials.lb-services-fields')

                    <div>
                        <p class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Backend servers') }}</p>
                        <p class="mb-3 text-xs text-brand-mist">{{ __('HAProxy will forward traffic to these servers. If they share a private network, the private IP is used automatically.') }}</p>
                        @include('livewire.servers.partials.lb-target-checkboxes')
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                    <button type="button" x-on:click="$dispatch('close-modal', 'org-create-haproxy-lb-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="createHAProxyLoadBalancer" wire:loading.attr="disabled" wire:target="createHAProxyLoadBalancer"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="createHAProxyLoadBalancer">{{ __('Create load balancer') }}</span>
                        <span wire:loading wire:target="createHAProxyLoadBalancer" class="inline-flex items-center gap-2"><x-spinner variant="white" size="sm" />{{ __('Configuring…') }}</span>
                    </button>
                </div>
            </div>
        </x-modal>

        {{-- ─── CREATE MANAGED (Hetzner) LB MODAL ──────────────────────────────── --}}
        <x-modal name="org-create-hetzner-lb-modal" max-width="2xl" focusable>
            <div class="bg-white">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create load balancer') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Provisions a Hetzner load balancer in your account and wires it up with the selected servers as targets. Region is taken from the targets.') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'org-create-hetzner-lb-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[70vh] space-y-6 overflow-y-auto p-6">
                    <div class="grid gap-5 sm:grid-cols-3 sm:items-end">
                        <div>
                            <x-input-label for="hz_lb_name" :value="__('Name')" />
                            <x-text-input id="hz_lb_name" wire:model="lb_name" class="mt-1 block w-full" placeholder="e.g. web-lb" />
                            @error('lb_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="hz_lb_type" :value="__('Type')" />
                            <select id="hz_lb_type" wire:model="lb_type" class="dply-input mt-1 block w-full">
                                @foreach (\App\Models\LoadBalancer::TYPES as $type)
                                    <option value="{{ $type }}">{{ \App\Models\LoadBalancer::typeLabel($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="hz_lb_algorithm" :value="__('Algorithm')" />
                            <select id="hz_lb_algorithm" wire:model="lb_algorithm" class="dply-input mt-1 block w-full">
                                <option value="round_robin">{{ __('Round robin') }}</option>
                                <option value="least_connections">{{ __('Least connections') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="max-w-xs">
                        <x-input-label for="hz_lb_network_id" :value="__('Private network ID (optional)')" />
                        <x-text-input id="hz_lb_network_id" wire:model="lb_network_id" class="mt-1 block w-full font-mono" placeholder="e.g. 1234567" />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('If set, targets connect over private IP. Leave blank to use public IPs.') }}</p>
                    </div>

                    @include('livewire.servers.partials.lb-services-fields')

                    <div>
                        <p class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Target servers') }}</p>
                        <p class="mb-3 text-xs text-brand-mist">{{ __('Pick Hetzner servers — the load balancer is created in the same account and region.') }}</p>
                        @include('livewire.servers.partials.lb-target-checkboxes')
                        @error('lb_target_server_ids') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                    <button type="button" x-on:click="$dispatch('close-modal', 'org-create-hetzner-lb-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="createHetznerLoadBalancer" wire:loading.attr="disabled" wire:target="createHetznerLoadBalancer"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50">
                        <span wire:loading.remove wire:target="createHetznerLoadBalancer">{{ __('Create load balancer') }}</span>
                        <span wire:loading wire:target="createHetznerLoadBalancer" class="inline-flex items-center gap-2"><x-spinner variant="white" size="sm" />{{ __('Creating…') }}</span>
                    </button>
                </div>
            </div>
        </x-modal>
    </div>
</div>
