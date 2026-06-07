<x-server-workspace-layout
    :server="$server"
    active="load-balancers"
    :title="__('Load Balancers')"
    :description="__('Load balancers that target this server. Manage all load balancers from the Networking section.')"
>
    @if (in_array('load-balancers', config('server_workspace.coming_soon_keys', []), true))
        <x-workspace-coming-soon
            :server="$server"
            icon="heroicon-o-arrows-right-left"
            :title="__('Load balancers')"
            :description="__('Spread traffic across this server and its peers with managed, health-checked load balancers — provisioned and wired up from your dashboard.')"
            :eyebrow="__('Load balancer preview')"
            :heroNote="__('Balancers targeting :server will be managed from Networking when this ships.', ['server' => $server->name])"
            :lines="[
                ['tone' => 'cmd', 'text' => '~ $ dply lb status'],
                ['tone' => 'muted', 'text' => 'BACKEND        STATE    CHECKS'],
                ['tone' => 'muted', 'text' => 'web-1:443      healthy  200 OK'],
                ['tone' => 'muted', 'text' => 'web-2:443      healthy  200 OK'],
                ['tone' => 'ok', 'text' => '2 backends · least-conn · 0 ejected'],
            ]"
            :features="[
                ['icon' => 'heart', 'title' => __('Health-checked backends'), 'body' => __('Active checks eject unhealthy nodes and bring them back automatically.')],
                ['icon' => 'arrows-right-left', 'title' => __('Smart algorithms'), 'body' => __('Round-robin or least-connections, switchable per balancer.')],
                ['icon' => 'shield-check', 'title' => __('TLS termination'), 'body' => __('Terminate TLS at the edge or pass through to your backends.')],
                ['icon' => 'squares-2x2', 'title' => __('Managed from Networking'), 'body' => __('Create and attach balancers without touching a config file.')],
            ]"
        />
    @else
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="space-y-6">

        {{-- ─── SECTION HEADER (always shown) ──────────────────────────────── --}}
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <div class="flex items-center gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Load balancers') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Manage load balancers') }}</h3>
                    </div>
                </div>
                <a href="{{ route('networking.index') }}?tab=load-balancers" wire:navigate
                    class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                    {{ __('Manage in Networking →') }}
                </a>
            </div>
            @if ($loadBalancers->isEmpty())
                <div class="px-6 py-8 sm:px-7">
                    <x-empty-state
                        borderless
                        icon="heroicon-o-arrows-right-left"
                        tone="sage"
                        :title="__('No load balancers yet')"
                        :description="__('Software (HAProxy) load balancers run on any server — free. Managed (Hetzner) LBs are fully redundant but cost extra.')"
                    />
                </div>
            @endif
        </section>

        {{-- ─── LOAD BALANCER CARDS ─────────────────────────────────────────── --}}
        @foreach ($loadBalancers as $lb)
            @php
                $statusPill = match ($lb->status) {
                    'running' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'label' => __('Running')],
                    'provisioning' => ['dot' => 'bg-sky-400', 'text' => 'text-sky-700', 'label' => __('Provisioning')],
                    'error' => ['dot' => 'bg-rose-500', 'text' => 'text-rose-700', 'label' => __('Error')],
                    default => ['dot' => 'bg-brand-mist', 'text' => 'text-brand-mist', 'label' => ucfirst($lb->status)],
                };
            @endphp
            <section class="dply-card overflow-hidden" wire:key="lb-{{ $lb->id }}">
                {{-- Card header --}}
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <x-icon-badge>
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div>
                            <h3 class="text-base font-semibold text-brand-ink">{{ $lb->name }}</h3>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 font-mono text-[11px] text-brand-mist">
                                <span>{{ strtoupper($lb->load_balancer_type) }}</span>
                                <span>{{ $lb->region }}</span>
                                @if ($lb->public_ipv4)
                                    <span>{{ $lb->public_ipv4 }}</span>
                                @endif
                                @if ($lb->private_ip)
                                    <span class="flex items-center gap-1">
                                        <x-heroicon-m-lock-closed class="h-2.5 w-2.5 text-emerald-500" />
                                        {{ $lb->private_ip }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($lb->isSoftware())
                            <span class="inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">HAProxy · {{ $lb->server?->name }}</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-700 ring-1 ring-sky-200">Hetzner managed</span>
                        @endif
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-[11px] font-medium ring-1 ring-brand-ink/10 {{ $statusPill['text'] }}">
                            @if ($lb->status === 'provisioning')
                                <x-spinner variant="forest" size="sm" />
                            @else
                                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $statusPill['dot'] }}"></span>
                            @endif
                            {{ $statusPill['label'] }}
                        </span>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('deleteLoadBalancer', ['{{ $lb->id }}'], @js(__('Delete :name?', ['name' => $lb->name])), @js(__('The load balancer and all its configuration will be permanently deleted from Hetzner. Targets and services are removed. Your servers are not affected.')), @js(__('Delete')), true)"
                            class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
                        >
                            {{ __('Delete') }}
                        </button>
                    </div>
                </div>

                @if ($lb->status === 'error' && $lb->error_message)
                    <div class="border-b border-rose-200 bg-rose-50 px-6 py-3 sm:px-7">
                        <p class="text-xs text-rose-800">{{ $lb->error_message }}</p>
                    </div>
                @endif

                <div class="grid divide-y divide-brand-ink/5 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                    {{-- Services --}}
                    <div class="px-6 py-5 sm:px-7">
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Services') }}</p>
                        @if ($lb->services->isEmpty())
                            <p class="text-sm text-brand-mist">{{ __('No services configured.') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($lb->services as $svc)
                                    <div class="flex items-center gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                        <span @class([
                                            'inline-flex shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase',
                                            'bg-sky-100 text-sky-700' => $svc->protocol === 'http',
                                            'bg-emerald-100 text-emerald-700' => $svc->protocol === 'https',
                                            'bg-violet-100 text-violet-700' => $svc->protocol === 'tcp',
                                        ])>{{ strtoupper($svc->protocol) }}</span>
                                        <code class="font-mono text-xs text-brand-ink">
                                            :{{ $svc->listen_port }} → :{{ $svc->destination_port }}
                                        </code>
                                        @if ($svc->sticky_sessions)
                                            <span class="ml-auto text-[10px] text-brand-mist">{{ __('sticky') }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Targets --}}
                    <div class="px-6 py-5 sm:px-7">
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Targets') }}</p>
                        @if ($lb->targets->isEmpty())
                            <p class="text-sm text-brand-mist">{{ __('No targets yet.') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($lb->targets as $target)
                                    <div class="flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                                        <span @class([
                                            'inline-block h-1.5 w-1.5 shrink-0 rounded-full',
                                            'bg-emerald-500' => $target->status === 'healthy',
                                            'bg-rose-500' => $target->status === 'unhealthy',
                                            'bg-amber-400' => ! in_array($target->status, ['healthy', 'unhealthy'], true),
                                        ])></span>
                                        <span class="min-w-0 flex-1 truncate text-sm text-brand-ink">{{ $target->server?->name ?? __('Unknown server') }}</span>
                                        <span class="font-mono text-[11px] text-brand-mist">{{ $target->server?->private_ip_address ?? $target->server?->ip_address }}</span>
                                        <button
                                            type="button"
                                            wire:click="removeTarget('{{ $target->id }}')"
                                            class="shrink-0 text-[11px] text-rose-600 hover:underline"
                                        >{{ __('Remove') }}</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Add target --}}
                        @if ($lb->status === 'running')
                            <div class="mt-3 flex gap-2">
                                <select
                                    wire:model="add_target_server_id"
                                    class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-forest focus:ring-1 focus:ring-brand-forest"
                                >
                                    <option value="">{{ __('Add server…') }}</option>
                                    @foreach ($orgServers as $s)
                                        @unless ($lb->targets->pluck('server_id')->contains($s->id))
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endunless
                                    @endforeach
                                </select>
                                <button
                                    type="button"
                                    wire:click="addTarget('{{ $lb->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="addTarget('{{ $lb->id }}')"
                                    class="inline-flex shrink-0 items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-forest/90 disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="addTarget('{{ $lb->id }}')">{{ __('Add') }}</span>
                                    <span wire:loading wire:target="addTarget('{{ $lb->id }}')">{{ __('…') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Algorithm badge --}}
                <div class="flex items-center gap-2 border-t border-brand-ink/5 bg-brand-sand/10 px-6 py-3 sm:px-7">
                    <span class="text-[11px] text-brand-mist">{{ __('Algorithm') }}:</span>
                    <span class="text-[11px] font-medium text-brand-ink">{{ $lb->algorithm === 'round_robin' ? __('Round robin') : __('Least connections') }}</span>
                    @if ($lb->hetzner_network_id)
                        <span class="ml-3 text-[11px] text-brand-mist">{{ __('Network') }}: <span class="font-mono">{{ $lb->hetzner_network_id }}</span></span>
                    @endif
                </div>
            </section>
        @endforeach

    </div>

    {{-- ─── CREATE SOFTWARE (HAPROXY) LOAD BALANCER MODAL ────────────────────── --}}
    <x-modal name="create-haproxy-lb-modal" max-width="2xl" focusable>
        <div class="bg-white">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Free · HAProxy') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create software load balancer') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Runs HAProxy on a server you already own. No extra cost — just the server. Dply writes the config and reloads over SSH.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'create-haproxy-lb-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-6 overflow-y-auto p-6">

                {{-- Name + algorithm --}}
                <div class="grid gap-5 sm:grid-cols-2 sm:items-end">
                    <div>
                        <x-input-label for="haproxy_lb_name" :value="__('Name')" />
                        <x-text-input id="haproxy_lb_name" wire:model="lb_name" class="mt-1 block w-full" />
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

                {{-- HAProxy server picker --}}
                <div>
                    <x-input-label for="haproxy_server_id" :value="__('HAProxy server')" />
                    <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Pick any server with the "Load balancer" role (HAProxy pre-installed). Or create one from the server wizard first.') }}</p>
                    <select id="haproxy_server_id" wire:model="haproxy_server_id" class="dply-input mt-2 block w-full">
                        <option value="">{{ __('Select a server…') }}</option>
                        @foreach ($orgServers as $s)
                            @php $role = data_get($s->meta, 'server_role', ''); @endphp
                            <option value="{{ $s->id }}" {{ $role === 'load_balancer' ? '' : '' }}>
                                {{ $s->name }}
                                @if ($role === 'load_balancer') ({{ __('load balancer role') }}) @endif
                                — {{ $s->ip_address }} · {{ $s->region }}
                            </option>
                        @endforeach
                    </select>
                    @error('haproxy_server_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>

                {{-- Services --}}
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Services') }}</p>
                        <button type="button" wire:click="addServiceRow" class="text-xs font-medium text-brand-sage hover:underline">
                            + {{ __('Add service') }}
                        </button>
                    </div>
                    <div class="space-y-3">
                        @foreach ($lb_services as $i => $svc)
                            <div class="grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:grid-cols-4 sm:items-end">
                                <div>
                                    <x-input-label :value="__('Protocol')" />
                                    <select wire:model="lb_services.{{ $i }}.protocol" class="dply-input mt-1 block w-full">
                                        <option value="http">HTTP</option>
                                        <option value="https">HTTPS</option>
                                        <option value="tcp">TCP</option>
                                    </select>
                                </div>
                                <div>
                                    <x-input-label :value="__('Listen port')" />
                                    <x-text-input wire:model="lb_services.{{ $i }}.listen_port" class="mt-1 block w-full font-mono" placeholder="80" />
                                    @error("lb_services.{$i}.listen_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <x-input-label :value="__('Destination port')" />
                                    <x-text-input wire:model="lb_services.{{ $i }}.destination_port" class="mt-1 block w-full font-mono" placeholder="8080" />
                                    @error("lb_services.{$i}.destination_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-end">
                                    @if (count($lb_services) > 1)
                                        <button type="button" wire:click="removeServiceRow({{ $i }})" class="text-xs font-medium text-rose-600 hover:underline">{{ __('Remove') }}</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Target servers --}}
                <div>
                    <p class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Backend servers') }}</p>
                    <p class="mb-3 text-xs text-brand-mist">{{ __('HAProxy will forward traffic to these servers. If they share a private network, the private IP is used automatically.') }}</p>
                    <div class="space-y-2">
                        @foreach ($orgServers as $s)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/10">
                                <input
                                    type="checkbox"
                                    wire:model="lb_target_server_ids"
                                    value="{{ $s->id }}"
                                    class="rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $s->name }}
                                        @if ($s->id === $server->id)
                                            <span class="ml-1 rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-medium text-brand-forest">{{ __('this server') }}</span>
                                        @endif
                                    </p>
                                    <p class="font-mono text-[11px] text-brand-mist">
                                        {{ $s->private_ip_address ?? $s->ip_address }} · {{ $s->region }}
                                        @if ($s->private_ip_address)
                                            <span class="text-emerald-600">· {{ __('private') }}</span>
                                        @endif
                                    </p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'create-haproxy-lb-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="createHAProxyLoadBalancer"
                    wire:loading.attr="disabled"
                    wire:target="createHAProxyLoadBalancer"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createHAProxyLoadBalancer">{{ __('Create load balancer') }}</span>
                    <span wire:loading wire:target="createHAProxyLoadBalancer" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
                        {{ __('Configuring…') }}
                    </span>
                </button>
            </div>
        </div>
    </x-modal>

    {{-- ─── CREATE LOAD BALANCER MODAL ─────────────────────────────────────── --}}
    <x-modal name="create-lb-modal" max-width="2xl" focusable>
        <div class="bg-white">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hetzner') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create load balancer') }}</h3>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Provisions a Hetzner load balancer in your account and wires it up with the selected servers as targets.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'create-lb-modal')" class="shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="max-h-[70vh] space-y-6 overflow-y-auto p-6">

                {{-- Name + type + algorithm --}}
                <div class="grid gap-5 sm:grid-cols-3 sm:items-end">
                    <div>
                        <x-input-label for="lb_name" :value="__('Name')" />
                        <x-text-input id="lb_name" wire:model="lb_name" class="mt-1 block w-full" />
                        @error('lb_name') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-input-label for="lb_type" :value="__('Type')" />
                        <select id="lb_type" wire:model="lb_type" class="dply-input mt-1 block w-full">
                            @foreach (\App\Models\LoadBalancer::TYPES as $type)
                                <option value="{{ $type }}">{{ \App\Models\LoadBalancer::typeLabel($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="lb_algorithm" :value="__('Algorithm')" />
                        <select id="lb_algorithm" wire:model="lb_algorithm" class="dply-input mt-1 block w-full">
                            <option value="round_robin">{{ __('Round robin') }}</option>
                            <option value="least_connections">{{ __('Least connections') }}</option>
                        </select>
                    </div>
                </div>

                {{-- Network ID --}}
                <div class="max-w-xs">
                    <x-input-label for="lb_network_id" :value="__('Private network ID (optional)')" />
                    <x-text-input id="lb_network_id" wire:model="lb_network_id" class="mt-1 block w-full font-mono"
                        :placeholder="$server->hetzner_network_id ?? 'e.g. 1234567'" />
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('If set, targets connect over private IP. Leave blank to use public IPs.') }}</p>
                </div>

                {{-- Services --}}
                <div>
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Services') }}</p>
                        <button type="button" wire:click="addServiceRow" class="text-xs font-medium text-brand-sage hover:underline">
                            + {{ __('Add service') }}
                        </button>
                    </div>
                    <div class="space-y-3">
                        @foreach ($lb_services as $i => $svc)
                            <div class="grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:grid-cols-4 sm:items-end">
                                <div>
                                    <x-input-label :value="__('Protocol')" />
                                    <select wire:model="lb_services.{{ $i }}.protocol" class="dply-input mt-1 block w-full">
                                        <option value="http">HTTP</option>
                                        <option value="https">HTTPS</option>
                                        <option value="tcp">TCP</option>
                                    </select>
                                </div>
                                <div>
                                    <x-input-label :value="__('Listen port')" />
                                    <x-text-input wire:model="lb_services.{{ $i }}.listen_port" class="mt-1 block w-full font-mono" placeholder="80" />
                                    @error("lb_services.{$i}.listen_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <x-input-label :value="__('Destination port')" />
                                    <x-text-input wire:model="lb_services.{{ $i }}.destination_port" class="mt-1 block w-full font-mono" placeholder="8080" />
                                    @error("lb_services.{$i}.destination_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex items-end">
                                    @if (count($lb_services) > 1)
                                        <button type="button" wire:click="removeServiceRow({{ $i }})" class="text-xs font-medium text-rose-600 hover:underline">
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Target servers --}}
                <div>
                    <p class="mb-3 text-sm font-semibold text-brand-ink">{{ __('Target servers') }}</p>
                    <div class="space-y-2">
                        @foreach ($orgServers as $s)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/10">
                                <input
                                    type="checkbox"
                                    wire:model="lb_target_server_ids"
                                    value="{{ $s->id }}"
                                    class="rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $s->name }}
                                        @if ($s->id === $server->id)
                                            <span class="ml-1 rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-medium text-brand-forest">{{ __('this server') }}</span>
                                        @endif
                                    </p>
                                    <p class="font-mono text-[11px] text-brand-mist">
                                        {{ $s->private_ip_address ?? $s->ip_address }} · {{ $s->region }}
                                    </p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4">
                <button type="button" x-on:click="$dispatch('close-modal', 'create-lb-modal')" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="createLoadBalancer"
                    wire:loading.attr="disabled"
                    wire:target="createLoadBalancer"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createLoadBalancer">{{ __('Create load balancer') }}</span>
                    <span wire:loading wire:target="createLoadBalancer" class="inline-flex items-center gap-2">
                        <x-spinner variant="white" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </div>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
    @endif
</x-server-workspace-layout>
