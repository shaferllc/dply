<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

    <div>
        <h1 class="text-xl font-semibold tracking-tight text-brand-ink">{{ __('Networking') }}</h1>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Load balancers, private networks, and routes across your workspace.') }}</p>
    </div>

        {{-- Tabs --}}
        <x-server-workspace-tablist :aria-label="__('Networking sections')" class="w-full">
            <x-server-workspace-tab
                id="net-tab-lb"
                icon="heroicon-o-arrows-right-left"
                :active="$tab === 'load-balancers'"
                wire:click="setTab('load-balancers')"
            >
                {{ __('Load balancers') }}
                @if ($loadBalancers->isNotEmpty())
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $loadBalancers->count() }}</span>
                @endif
            </x-server-workspace-tab>
            <x-server-workspace-tab
                id="net-tab-networks"
                icon="heroicon-o-share"
                :active="$tab === 'networks'"
                wire:click="setTab('networks')"
            >
                {{ __('Networks') }}
                @if ($networks->isNotEmpty())
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-brand-sand/70 px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">{{ $networks->count() }}</span>
                @endif
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- LOAD BALANCERS TAB                                                  --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        @if ($tab === 'load-balancers')
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Load balancers') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('All load balancers') }}</h3>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" x-on:click="$dispatch('open-modal', 'org-create-haproxy-lb-modal')"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                            <x-heroicon-o-plus class="h-4 w-4" />{{ __('Software (HAProxy)') }}
                        </button>
                        <button type="button" x-on:click="$dispatch('open-modal', 'org-create-hetzner-lb-modal')"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-plus class="h-4 w-4" />{{ __('Managed (Hetzner)') }}
                        </button>
                    </div>
                </div>

                @if ($loadBalancers->isEmpty())
                    <div class="px-6 py-8 sm:px-7">
                        <x-empty-state borderless icon="heroicon-o-arrows-right-left" tone="sage"
                            :title="__('No load balancers yet')"
                            :description="__('Software (HAProxy) LBs run on any server you already own — free. Managed Hetzner LBs are fully redundant but cost extra.')" />
                    </div>
                @else
                    <div class="divide-y divide-brand-ink/5">
                        @foreach ($loadBalancers as $lb)
                            @php
                                $pill = match ($lb->status) {
                                    'running'      => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'label' => __('Running')],
                                    'provisioning' => ['dot' => 'bg-sky-400',     'text' => 'text-sky-700',     'label' => __('Provisioning')],
                                    default        => ['dot' => 'bg-rose-500',    'text' => 'text-rose-700',    'label' => ucfirst($lb->status)],
                                };
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-7" wire:key="lb-{{ $lb->id }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="font-semibold text-brand-ink">{{ $lb->name }}</p>
                                            @if ($lb->isSoftware())
                                                <span class="rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">HAProxy · {{ $lb->server?->name }}</span>
                                            @else
                                                <span class="rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 ring-1 ring-sky-200">Hetzner</span>
                                            @endif
                                        </div>
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 font-mono text-[11px] text-brand-mist">
                                            @if ($lb->public_ipv4) <span>{{ $lb->public_ipv4 }}</span> @endif
                                            @if ($lb->private_ip)  <span class="flex items-center gap-1"><x-heroicon-m-lock-closed class="h-2.5 w-2.5 text-emerald-500"/>{{ $lb->private_ip }}</span> @endif
                                            <span>{{ strtoupper($lb->load_balancer_type) }} · {{ $lb->region }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $pill['text'] }}">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $pill['dot'] }}"></span>
                                        {{ $pill['label'] }}
                                    </span>
                                    <span class="text-[11px] text-brand-mist">{{ $lb->targets->count() }} {{ __('targets') }}</span>
                                    <button type="button"
                                        wire:click="deleteLoadBalancer('{{ $lb->id }}')"
                                        class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        {{-- NETWORKS TAB                                                         --}}
        {{-- ═══════════════════════════════════════════════════════════════════ --}}
        @if ($tab === 'networks')
            <section class="dply-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Private networks') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('All networks') }}</h3>
                        </div>
                    </div>
                    <button type="button" x-on:click="$dispatch('open-modal', 'create-network-modal')"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90">
                        <x-heroicon-o-plus class="h-4 w-4" />{{ __('Create network') }}
                    </button>
                </div>

                @if ($networks->isEmpty())
                    <div class="px-6 py-8 sm:px-7">
                        <x-empty-state borderless icon="heroicon-o-share" tone="sage"
                            :title="__('No private networks yet')"
                            :description="__('Create a private network to let your servers communicate on private IPs — keeping database, cache, and app traffic off the public internet.')" />
                    </div>
                @else
                    @foreach ($networks as $network)
                        @php $routes = $routesByNetwork[$network->id] ?? []; @endphp
                        <div class="border-b border-brand-ink/5 last:border-0" wire:key="net-{{ $network->id }}">
                            {{-- Network header --}}
                            <div class="flex flex-wrap items-center justify-between gap-3 bg-brand-sand/10 px-6 py-4 sm:px-7">
                                <div>
                                    <p class="font-semibold text-brand-ink">{{ $network->name }}
                                        <span class="ml-2 font-mono text-xs font-normal text-brand-mist">{{ $network->ip_range }}</span>
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-brand-mist">
                                        {{ ucfirst($network->provider) }} · ID {{ $network->provider_id ?? '—' }} · {{ $network->servers->count() }} {{ __('servers') }}
                                    </p>
                                </div>
                                <button type="button"
                                    wire:click="deleteNetwork('{{ $network->id }}')"
                                    class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">
                                    {{ __('Delete') }}
                                </button>
                            </div>

                            {{-- Attached servers --}}
                            @if ($network->servers->isNotEmpty())
                                <div class="grid grid-cols-2 gap-2 px-6 py-4 sm:grid-cols-3 sm:px-7 lg:grid-cols-4">
                                    @foreach ($network->servers as $s)
                                        <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                                            <p class="truncate text-xs font-semibold text-brand-ink">{{ $s->name }}</p>
                                            <p class="font-mono text-[11px] text-brand-mist">{{ $s->private_ip_address ?? __('pending…') }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Routes --}}
                            @if ($network->isHetzner())
                                @php $gatewayServers = $network->servers->whereNotNull('private_ip_address'); @endphp
                                <div class="border-t border-brand-ink/5 px-6 py-4 sm:px-7">
                                    <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Routes') }}</p>
                                    @if (! empty($routes))
                                        <div class="mb-3 space-y-2">
                                            @foreach ($routes as $route)
                                                @php $gwServer = $orgServers->firstWhere('private_ip_address', $route['gateway']); @endphp
                                                <div class="flex items-center justify-between gap-4 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-brand-ink">
                                                        <span class="text-brand-mist">{{ __('Traffic for') }}</span>
                                                        <code class="font-mono text-brand-ink">{{ $route['destination'] }}</code>
                                                        <span class="text-brand-mist">{{ __('goes through') }}</span>
                                                        <span class="font-medium text-brand-ink">
                                                            {{ $gwServer?->name ?? $route['gateway'] }}
                                                            <span class="font-mono text-[11px] font-normal text-brand-mist">{{ $route['gateway'] }}</span>
                                                        </span>
                                                    </div>
                                                    <button type="button"
                                                        wire:click="deleteRoute('{{ $network->id }}', '{{ $route['destination'] }}', '{{ $route['gateway'] }}')"
                                                        class="shrink-0 text-[11px] font-medium text-rose-600 hover:underline">
                                                        {{ __('Remove') }}
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($gatewayServers->isEmpty())
                                        <p class="text-sm text-brand-mist">{{ __('Routes need at least one server on this network with a private IP to act as the gateway.') }}</p>
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
                                            class="space-y-3"
                                        >
                                            @if (empty($routes))
                                                <p class="text-sm text-brand-mist">{{ __('Add a route if you need a server to forward traffic for another range — for example a WireGuard VPN gateway.') }}</p>
                                            @endif

                                            {{-- Destination presets --}}
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-[11px] font-medium text-brand-mist">{{ __('Common:') }}</span>
                                                <button type="button" x-on:click="preset('10.8.0.0/24')"
                                                    class="rounded-full border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                                                    {{ __('WireGuard VPN clients') }}
                                                </button>
                                                <button type="button" x-on:click="preset('')"
                                                    class="rounded-full border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                                                    {{ __('Custom range') }}
                                                </button>
                                            </div>

                                            {{-- Sentence-framed inputs: Send traffic for [CIDR] through [server] --}}
                                            <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
                                                <div class="grow basis-48">
                                                    <x-input-label :value="__('Send traffic for')" />
                                                    <x-text-input wire:model.live="route_destination.{{ $network->id }}" class="mt-1 block w-full font-mono" placeholder="192.168.1.0/24" />
                                                </div>
                                                <span class="pb-2.5 text-sm text-brand-mist">{{ __('through') }}</span>
                                                <div class="grow basis-48">
                                                    <x-input-label :value="__('this server')" />
                                                    <select wire:model="route_gateway_server.{{ $network->id }}" class="dply-input mt-1 block w-full">
                                                        <option value="">{{ __('Choose a server…') }}</option>
                                                        @foreach ($gatewayServers as $s)
                                                            <option value="{{ $s->id }}">{{ $s->name }} — {{ $s->private_ip_address }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <button type="button" wire:click="addRoute('{{ $network->id }}')"
                                                    wire:loading.attr="disabled" wire:target="addRoute('{{ $network->id }}')"
                                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50">
                                                    <span wire:loading.remove wire:target="addRoute('{{ $network->id }}')">{{ __('Add route') }}</span>
                                                    <span wire:loading wire:target="addRoute('{{ $network->id }}')" class="inline-flex items-center gap-2"><x-spinner variant="white" size="sm"/>{{ __('Adding…') }}</span>
                                                </button>
                                            </div>

                                            {{-- Live plain-language explanation --}}
                                            <p x-show="cidrOk && gw" x-cloak class="text-[11px] text-brand-moss">
                                                {{ __('Any server on this network trying to reach') }}
                                                <code class="font-mono" x-text="dest.trim()"></code>
                                                {{ __('will send it to') }}
                                                <span class="font-medium" x-text="gwName(gw)"></span>,
                                                {{ __('which forwards it on.') }}
                                            </p>
                                            <p x-show="dest.trim() && ! cidrOk" x-cloak class="text-[11px] text-rose-700">
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
                @endif
            </section>
        @endif

    {{-- Create network modal --}}
    <x-modal name="create-network-modal" max-width="lg" focusable>
        <div class="bg-white">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-share class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
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
                                    <p class="font-mono text-[11px] text-brand-mist">{{ $s->ip_address }} · {{ $s->region }}</p>
                                </div>
                                @if ($s->private_ip_address)
                                    <span class="ml-auto text-[11px] text-emerald-600">{{ __('already on a network') }}</span>
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
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Free · HAProxy') }}</p>
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
                    <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Pick any server with the "Load balancer" role (HAProxy pre-installed). Or create one from the server wizard first.') }}</p>
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
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
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
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('If set, targets connect over private IP. Leave blank to use public IPs.') }}</p>
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
