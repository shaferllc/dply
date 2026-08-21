{{-- Embedded strip inside the Overview card — no card of its own. --}}
<div class="border-t border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-globe-alt"
        :title="__('Network')"
        :note="__('The private network this app\'s database, cache and servers share. New clusters are created inside it.')"
    >
        @if ($attached)
            <x-slot:actions>
                <span class="inline-flex items-center rounded-md bg-brand-forest/15 px-2 py-0.5 text-2xs font-semibold text-brand-forest">
                    {{ $attached->name }}
                </span>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    <div class="px-4 py-3 sm:px-5 space-y-3">
        @if ($available->isEmpty())
            <p class="text-sm text-brand-moss">
                {{ __('No networks are recorded for this region yet. Import the ones on your DigitalOcean account to attach one.') }}
            </p>
            <button type="button" wire:click="sync" wire:loading.attr="disabled"
                    class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                {{ __('Find networks') }}
            </button>
        @else
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 sm:max-w-sm">
                    <label class="block text-sm font-semibold text-brand-ink" for="serverless-network">{{ __('Network') }}</label>
                    <select id="serverless-network" wire:model="networkId"
                            class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                        <option value="">{{ __('Not attached — DigitalOcean picks the default') }}</option>
                        @foreach ($available as $network)
                            <option value="{{ $network->id }}">
                                {{ $network->name }}{{ $network->ip_range ? ' · '.$network->ip_range : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="button" wire:click="attach" wire:loading.attr="disabled"
                        class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                    {{ __('Save') }}
                </button>
                <button type="button" wire:click="sync" wire:loading.attr="disabled"
                        class="text-xs font-semibold text-brand-forest hover:underline disabled:opacity-70">
                    {{ __('Refresh list') }}
                </button>
            </div>

            @if ($stale)
                <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
                    {{ __('A database or cache was created before this network was attached. DigitalOcean cannot move an existing cluster between networks — only clusters provisioned from now on will join it.') }}
                </div>
            @endif

            @if ($attached)
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('IP range') }}</dt>
                        <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $attached->ip_range ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Region') }}</dt>
                        <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $attached->network_zone ?: '—' }}</dd>
                    </div>
                </dl>

                {{-- Vapor provisions a dedicated jumpbox for this; dply lists the
                     servers the org already runs on the network instead. --}}
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Servers on this network') }}</p>
                    @if ($members->isEmpty())
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('None yet. A server on this network can reach the app\'s database and cache privately — useful for running migrations or opening a shell against them.') }}
                        </p>
                    @else
                        <ul class="mt-1 space-y-1">
                            @foreach ($members as $member)
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <a href="{{ route('servers.show', $member) }}" wire:navigate class="truncate font-medium text-brand-forest hover:underline">{{ $member->name }}</a>
                                    <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $member->private_ip_address ?: $member->ip_address }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <p class="text-xs text-brand-moss">
                {{ __('The app itself always reaches its database over the public hostname — serverless functions cannot join a network. Attaching one places the cluster\'s private interface, so servers on the same network reach it without leaving DigitalOcean.') }}
            </p>
        @endif
    </div>
</div>
