<div class="space-y-6">
    @php
        $group = $this->group;
        $lb = $this->loadBalancer;
        $enabled = (bool) ($group['enabled'] ?? false);
        $substrateLabel = ($group['substrate'] ?? null) === 'hetzner' ? __('Hetzner cloud LB') : __('HAProxy (software)');
        $stateStyles = [
            'active' => ['bg-emerald-100 text-emerald-800', __('Active')],
            'provisioning' => ['bg-amber-100 text-amber-800', __('Provisioning')],
            'replaying' => ['bg-amber-100 text-amber-800', __('Replicating')],
            'deploying' => ['bg-amber-100 text-amber-800', __('Deploying')],
            'draining' => ['bg-orange-100 text-orange-800', __('Draining')],
            'errored' => ['bg-rose-100 text-rose-800', __('Errored')],
        ];
    @endphp

    {{-- Live refresh while backends/LB are still coming up. --}}
    @if ($this->isConverging || ($lb && $lb->status === 'provisioning'))
        <div wire:poll.5s="refreshState" class="hidden" aria-hidden="true"></div>
    @endif

    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('Load-balanced backends') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Serve this site from multiple app servers behind a load balancer. With two or more backends you can deploy with the Rolling or Canary methods. Each backend runs the same code and environment as this site.') }}
            </p>
        </div>
    </div>

    @if (! $this->canManage())
        <div class="dply-card p-6 text-sm text-brand-moss">
            {{ __('Backends are only available for VM-hosted sites with SSH access.') }}
        </div>
    @else
        {{-- Group / balancer status --}}
        @if ($enabled)
            <div class="dply-card p-6">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Substrate') }}</p>
                        <p class="mt-1 text-sm font-semibold text-brand-ink">{{ $substrateLabel }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Load balancer') }}</p>
                        <p class="mt-1 text-sm font-semibold text-brand-ink">
                            @if ($lb)
                                {{ ucfirst($lb->status) }}
                            @else
                                {{ __('Provisioning…') }}
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Public IP') }}</p>
                        <p class="mt-1 text-sm font-semibold text-brand-ink">{{ $lb?->public_ipv4 ?: '—' }}</p>
                    </div>
                </div>

                @if ($lb?->public_ipv4)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        {{ __('Point this site’s domain at the load balancer: create an A record to :ip. (DNS is not updated automatically yet.)', ['ip' => $lb->public_ipv4]) }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Backend list --}}
        @if ($this->backends->isNotEmpty())
            <div class="dply-card overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-brand-ink/10 text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">
                            <th class="px-5 py-3">{{ __('Server') }}</th>
                            <th class="px-5 py-3">{{ __('Role') }}</th>
                            <th class="px-5 py-3">{{ __('State') }}</th>
                            <th class="px-5 py-3">{{ __('Weight') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->backends as $backend)
                            @php([$badgeClass, $badgeLabel] = $stateStyles[$backend->state] ?? ['bg-brand-sand text-brand-ink', ucfirst($backend->state)])
                            <tr class="border-b border-brand-ink/5 last:border-0">
                                <td class="px-5 py-3 font-medium text-brand-ink">{{ $backend->server->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-brand-moss">{{ $backend->isPrimary() ? __('Primary') : __('Replica') }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    @if ($backend->drained_at)
                                        <span class="ml-1 text-xs text-orange-700">{{ __('(out of rotation)') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-brand-moss">{{ $backend->weight }}%</td>
                                <td class="px-5 py-3 text-right">
                                    @if (! $backend->isPrimary())
                                        <button
                                            type="button"
                                            wire:click="removeBackend('{{ $backend->id }}')"
                                            wire:confirm="{{ __('Remove this backend? It is drained from the load balancer and its server is destroyed. This cannot be undone.') }}"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    @else
                                        <span class="text-xs text-brand-mist">{{ __('always on') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Add a backend --}}
        <div class="dply-card p-6 sm:p-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Add a backend') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Provisions a new app server (cloning this site’s server placement), replicates the site onto it, deploys, and adds it behind the load balancer. The first backend also provisions the balancer.') }}
            </p>

            @unless ($enabled)
                <div class="mt-5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Load balancer type') }}</p>
                    <div class="mt-2 grid gap-2.5 sm:grid-cols-2">
                        <label @class([
                            'flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition-colors',
                            'border-brand-forest/40 bg-brand-sage/10 ring-1 ring-brand-forest/20' => $substrate === 'haproxy',
                            'border-brand-ink/10 hover:bg-brand-sand/30' => $substrate !== 'haproxy',
                        ])>
                            <input type="radio" wire:model="substrate" value="haproxy" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-brand-ink">{{ __('HAProxy (software)') }}</span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('A dedicated HAProxy host dply manages. Supports weighted traffic — required for Canary.') }}</span>
                            </span>
                        </label>
                        <label @class([
                            'flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition-colors',
                            'border-brand-forest/40 bg-brand-sage/10 ring-1 ring-brand-forest/20' => $substrate === 'hetzner',
                            'border-brand-ink/10 hover:bg-brand-sand/30' => $substrate !== 'hetzner',
                        ])>
                            <input type="radio" wire:model="substrate" value="hetzner" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Hetzner cloud LB') }}</span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Provider-managed, no host to run. Rolling only — no weighted Canary.') }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            @endunless

            <div class="mt-5 flex justify-end">
                <button
                    type="button"
                    wire:click="addBackend"
                    wire:confirm="{{ __('Provision a new backend server? This creates a new cloud server and may incur cost.') }}"
                    wire:loading.attr="disabled"
                    wire:target="addBackend"
                    class="inline-flex items-center gap-2 rounded-xl bg-brand-forest px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-60"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    <span wire:loading.remove wire:target="addBackend">{{ __('Add backend') }}</span>
                    <span wire:loading wire:target="addBackend">{{ __('Starting…') }}</span>
                </button>
            </div>
        </div>
    @endif
</div>
