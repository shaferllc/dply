<x-server-workspace-layout
    :server="$server"
    active="worker-pool"
    :title="__('Worker Pool')"
    :description="__('Clone this worker and scale background capacity. The pool keeps one primary (scheduler owner); the rest are queue-worker replicas.')"
    :context-site="null"
>
    @if (! $pool)
        {{-- No pool yet: offer to create one from this worker. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scaling') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create a worker pool') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Turn this worker into the primary of a pool. You can then scale to N workers — each clone replays this server’s sites and joins the same queue.') }}
                    </p>
                </div>
            </div>

            @if ($server->isWorkerHost())
                <form wire:submit="createPool" class="space-y-5 px-6 py-6 sm:px-7">
                    <div>
                        <x-input-label for="pool_name" :value="__('Pool name')" />
                        <x-text-input id="pool_name" wire:model="pool_name" class="mt-2 block w-full text-sm" />
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button type="submit">{{ __('Create pool') }}</x-primary-button>
                    </div>
                </form>
            @else
                <div class="px-6 py-6 sm:px-7">
                    <p class="text-sm text-brand-moss">{{ __('This server is not a worker host, so it cannot start a worker pool. Worker pools are for servers with the worker role (queue_worker profile).') }}</p>
                </div>
            @endif
        </section>
    @else
        @php
            $active = $pool->activeMemberCount();
            $healthy = $members->filter(fn ($m) => $m->isReady() && in_array($m->poolMemberState(), [null, \App\Models\WorkerPool::MEMBER_ACTIVE], true) || $m->isPoolPrimary())->count();
        @endphp

        {{-- Scale control --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $pool->name }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scale workers') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Desired :desired · active :active · healthy :healthy · max :max · status :status', [
                            'desired' => $pool->desired_count,
                            'active' => $active,
                            'healthy' => $healthy,
                            'max' => $pool->max_size,
                            'status' => $pool->status,
                        ]) }}
                    </p>
                </div>
            </div>

            <form wire:submit="scale" class="flex flex-wrap items-end gap-4 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="desired_count" :value="__('Desired worker count (incl. primary)')" />
                    <x-text-input id="desired_count" type="number" min="1" :max="$pool->max_size" wire:model="desired_count" class="mt-2 block w-32 text-sm" />
                    <x-input-error :messages="$errors->get('desired_count')" class="mt-2" />
                </div>
                <div class="pb-0.5">
                    <x-primary-button type="submit">{{ __('Apply scale') }}</x-primary-button>
                </div>
                @php
                    $delta = (int) $desired_count - $active;
                    $costDelta = abs($delta) * $perWorkerCents;
                    $costLabel = $perWorkerCents > 0 ? ' ≈ $'.number_format($costDelta / 100, 2).'/mo' : '';
                @endphp
                @if ($delta !== 0)
                    <p class="pb-1 text-xs text-brand-moss">
                        {{ $delta > 0
                            ? __('+:n server(s) will be provisioned (billable):cost.', ['n' => $delta, 'cost' => $costLabel])
                            : __(':n server(s) will be drained and destroyed (saves:cost).', ['n' => abs($delta), 'cost' => $costLabel]) }}
                    </p>
                @endif
            </form>
        </section>

        {{-- Autoscaling --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Autoscaling') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scale by queue backlog') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('When enabled, dply checks the queue backlog every 5 minutes and sets the desired worker count to backlog ÷ jobs-per-worker, clamped to your min/max.') }}
                        @if (! empty($pool->meta['autoscale']['last_backlog']))
                            {{ __('Last backlog: :n.', ['n' => $pool->meta['autoscale']['last_backlog']]) }}
                        @endif
                    </p>
                </div>
            </div>
            <form wire:submit="saveAutoscale" class="space-y-4 px-6 py-6 sm:px-7">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="as_enabled" class="h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                    <span class="text-sm font-medium text-brand-ink">{{ __('Enable autoscaling') }}</span>
                </label>
                <div class="flex flex-wrap gap-4">
                    <div>
                        <x-input-label for="as_min" :value="__('Min workers')" />
                        <x-text-input id="as_min" type="number" min="1" wire:model="as_min" class="mt-2 block w-28 text-sm" />
                    </div>
                    <div>
                        <x-input-label for="as_max" :value="__('Max workers')" />
                        <x-text-input id="as_max" type="number" min="1" :max="$pool->max_size" wire:model="as_max" class="mt-2 block w-28 text-sm" />
                    </div>
                    <div>
                        <x-input-label for="as_backlog" :value="__('Jobs per worker')" />
                        <x-text-input id="as_backlog" type="number" min="1" wire:model="as_backlog" class="mt-2 block w-32 text-sm" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">{{ __('Save autoscaling') }}</x-primary-button>
                </div>
            </form>
        </section>

        {{-- Cross-region exposure plan: which private backends must be exposed
             + this clone's IP allowlisted. dply does not open these automatically. --}}
        @php $plan = $this->exposurePlan(); @endphp
        @if (! empty($plan))
            <section class="dply-card mt-6 overflow-hidden border border-amber-200">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-amber-200 bg-amber-50 px-6 py-4 sm:px-7">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-amber-900">{{ __('Cross-region backend exposure') }}</h2>
                        <p class="mt-1 text-sm text-amber-800">{{ __('These workers reach private services over the public network. dply can bind each backend (password-gated) and allowlist only these worker IPs at the firewall.') }}</p>
                    </div>
                    <button type="button" wire:click="applyExposure" wire:confirm="{{ __('Bind these backends and allowlist the worker IPs now?') }}" class="shrink-0 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">{{ __('Expose & allowlist now') }}</button>
                </div>
                @php $exposure = $pool->meta['exposure'] ?? null; @endphp
                @if ($exposure)
                    <div class="border-b border-amber-100 bg-amber-50/50 px-6 py-3 text-xs sm:px-7">
                        @foreach (($exposure['applied'] ?? []) as $line)
                            <p class="text-emerald-800">✓ {{ $line }}</p>
                        @endforeach
                        @foreach (($exposure['warnings'] ?? []) as $line)
                            <p class="text-amber-900">⚠ {{ $line }}</p>
                        @endforeach
                    </div>
                @endif
                <div class="divide-y divide-amber-100">
                    @foreach ($plan as $e)
                        <div class="px-6 py-3 text-sm sm:px-7">
                            <span class="font-medium text-brand-ink">{{ $e['server_name'] ?? $e['server_id'] }}</span>
                            <span class="text-brand-moss">
                                — {{ __('expose') }} {{ implode(', ', array_map(fn ($k) => $k, $e['keys'] ?? [])) }}
                                @if (! empty($e['ports'])) ({{ __('port(s)') }} {{ implode(', ', $e['ports']) }}) @endif
                                · {{ __('allowlist') }} <code class="rounded bg-amber-100 px-1">{{ ($e['member_ip'] ?? '?') }}/32</code>
                            </span>
                            <a href="{{ route('servers.overview', $e['server_id']) }}" wire:navigate class="ml-1 font-medium text-brand-forest hover:underline">{{ __('open backend') }}</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Add a worker in another region/provider (Phase 2). --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cross-region') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add a worker in another region') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Provision one replica in a different region (same provider). Its env is rewritten to reach your backends over their public address — you’ll then need to expose + allowlist those backends (shown above once it’s ready).') }}
                    </p>
                </div>
            </div>
            <form wire:submit="addCrossRegion" class="space-y-4 px-6 py-6 sm:px-7">
                <div class="flex flex-wrap gap-4">
                    <div>
                        <x-input-label for="cr_provider" :value="__('Provider')" />
                        <select id="cr_provider" wire:model.live="cr_provider" class="dply-input mt-2 w-48 text-sm">
                            <option value="">{{ __('Same as source') }} ({{ $server->provider->value }})</option>
                            @foreach ($providerOptions as $p)
                                <option value="{{ $p }}">{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($cr_provider !== '' && $cr_provider !== $server->provider->value)
                        <div>
                            <x-input-label for="cr_credential_id" :value="__('Credential')" />
                            <select id="cr_credential_id" wire:model.live="cr_credential_id" class="dply-input mt-2 w-56 text-sm">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($credentialOptions as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <x-input-label for="cr_region" :value="__('Region')" />
                        @if (! empty($cr_regions))
                            <select id="cr_region" wire:model.live="cr_region" class="dply-input mt-2 w-56 text-sm">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($cr_regions as $r)
                                    <option value="{{ $r['value'] }}">{{ $r['label'] ?? $r['value'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input id="cr_region" wire:model="cr_region" class="mt-2 block w-48 text-sm" placeholder="{{ $server->region }}" />
                        @endif
                    </div>
                    <div>
                        <x-input-label for="cr_size" :value="__('Size')" />
                        @if (! empty($cr_sizes))
                            <select id="cr_size" wire:model="cr_size" class="dply-input mt-2 w-64 text-sm">
                                <option value="">{{ __('Default (match source)') }}</option>
                                @foreach ($cr_sizes as $s)
                                    <option value="{{ $s['value'] }}">{{ $s['label'] ?? $s['value'] }}@isset($s['price_monthly'])@if($s['price_monthly']) — ${{ number_format((float) $s['price_monthly'], 2) }}/mo @endif @endisset</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input id="cr_size" wire:model="cr_size" class="mt-2 block w-48 text-sm" placeholder="{{ $server->size }}" />
                        @endif
                    </div>
                </div>
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="cr_ack_secrets" class="mt-0.5 h-4 w-4 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/30" />
                    <span class="text-xs text-brand-moss">{{ __('I understand this server’s secrets (.env, including credentials) will be replicated to the new region/provider.') }}</span>
                </label>
                <div class="flex justify-end">
                    <x-primary-button type="submit">{{ __('Provision cross-region worker') }}</x-primary-button>
                </div>
            </form>
        </section>

        {{-- Members --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Members') }}</h2>
            </div>
            <div class="divide-y divide-brand-ink/10">
                @foreach ($members as $member)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('servers.overview', $member) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">{{ $member->name }}</a>
                                @if ($member->isPoolPrimary())
                                    <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Primary') }}</span>
                                @else
                                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Replica') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ $member->region }} · {{ $member->size }} · {{ __('status') }}: {{ $member->status }}@if ($member->poolMemberState()) · {{ $member->poolMemberState() }} @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! $member->isPoolPrimary())
                                <button type="button" wire:click="promote('{{ $member->id }}')" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Promote') }}</button>
                                <button type="button" wire:click="removeMember('{{ $member->id }}')" wire:confirm="{{ __('Drain and destroy this worker?') }}" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            @else
                                <span class="text-xs text-brand-mist">{{ __('Promote another member to remove this one') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <p class="mt-4 text-xs text-brand-moss">
            {{ __('Same-region workers join this server’s private network (env copied verbatim). Cross-region workers reach backends over the public network (env rewritten) and require you to expose + allowlist those backends. Backend exposure is not automated yet.') }}
        </p>
    @endif
</x-server-workspace-layout>
