{{-- Band 02 — Why it's that number.
     Replaces fleet-table + bill-preview. The tier ladder does the job the
     "What would it cost?" calculator did, and each lever states where it is
     actually changed: the tier is derived from server count and pushed to
     Stripe, so it can be set neither here nor there. --}}
@php
    $ladder = $this->planLadder;
    $next = $this->nextTier;
    $isYearly = $this->subscriptionInterval === 'year';

    $badge = 'ms-2 inline-flex items-center rounded-md border px-1.5 py-px align-[1px] font-mono text-2xs uppercase tracking-wide';
@endphp

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-chart-bar-square"
        :title="__('Plan')"
        :note="__('Set by how many servers dply manages — not their size.')"
    />

    <div class="px-3 py-4 sm:px-4">
        {{-- Tier ladder. --}}
        <ul class="flex overflow-hidden rounded-xl border border-brand-ink/10">
            @foreach ($ladder as $rung)
                <li @class([
                    'min-w-0 flex-1 border-e border-brand-ink/10 px-3 py-2 last:border-e-0',
                    'bg-brand-sand/45' => $rung['current'],
                    'bg-white' => ! $rung['current'],
                ])>
                    <p class="truncate text-xs font-semibold text-brand-ink">{{ $rung['label'] }}</p>
                    <p class="mt-0.5 truncate font-mono text-2xs text-brand-mist">
                        @if ($rung['max'] === null)
                            {{ __(':min+ servers', ['min' => $rung['min']]) }}
                        @elseif ($rung['min'] >= $rung['max'])
                            {{ trans_choice(':n server|:n servers', $rung['max'], ['n' => $rung['max']]) }}
                        @else
                            {{ __(':min–:max servers', ['min' => $rung['min'], 'max' => $rung['max']]) }}
                        @endif
                    </p>
                    <p @class([
                        'mt-1 font-mono text-sm font-semibold',
                        'text-brand-forest' => $rung['current'],
                        'text-brand-ink' => ! $rung['current'],
                    ])>${{ number_format($rung['price'], 0) }}</p>
                </li>
            @endforeach
        </ul>

        {{-- The crossing. This is the fact neither billing page told you. --}}
        @if ($next && $next['servers_until'] <= 1)
            <div class="mt-3 flex flex-wrap items-center gap-3 rounded-e-xl border border-s-[3px] border-brand-ink/10 border-s-brand-forest bg-brand-sand/20 px-3 py-2.5">
                <p class="min-w-0 flex-1 text-sm text-brand-ink">
                    {{ __('Your next billable server moves this workspace to :plan, and Stripe bills the difference that day.', ['plan' => $next['label']]) }}
                </p>
                <span class="shrink-0 whitespace-nowrap font-mono text-sm font-semibold text-brand-ink">
                    +${{ number_format($next['delta'], 2) }}{{ __('/mo') }}
                </span>
            </div>
        @endif

        {{-- Levers. Each says where it is genuinely changed. --}}
        <dl class="mt-5 border-t border-brand-ink/10">
            @if ($this->subscription)
                <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 py-3">
                    <div class="min-w-0 flex-1">
                        <dt class="text-sm font-semibold text-brand-ink">
                            {{ __('Billing interval') }}
                            <span class="{{ $badge }} border-brand-forest/40 bg-brand-sage/10 text-brand-forest">{{ __('In dply') }}</span>
                        </dt>
                        <dd class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                            {{ __('Yearly takes 20% off the plan fee. Switching is prorated immediately.') }}
                        </dd>
                    </div>
                    <button type="button" wire:click="switchInterval" class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ $isYearly ? __('Switch to monthly') : __('Switch to yearly') }}
                    </button>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 py-3">
                    <div class="min-w-0 flex-1">
                        <dt class="text-sm font-semibold text-brand-ink">
                            {{ __('Payment method, receipts, tax ID') }}
                            <span class="{{ $badge }} border-brand-sage/40 text-brand-sage">{{ __('Stripe') }}</span>
                        </dt>
                        <dd class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                            {{ __('Card details and invoice history live with Stripe, not with dply.') }}
                        </dd>
                    </div>
                    <button type="button" wire:click="portal" class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Open Stripe portal') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                    </button>
                </div>
        </dl>

        {{-- Which servers count. Collapsed so the page stays short, but the
             per-server reason stays reachable — "why isn't this server on my
             bill?" is the question the old always-visible fleet table answered,
             and a bare count cannot. --}}
        <details class="mt-4 rounded-xl border border-brand-ink/10 bg-white">
            <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-brand-moss hover:text-brand-ink">
                {{ __('Which servers count') }}
                <span class="ms-1 font-mono font-normal text-brand-mist">{{ $this->billableServers->count() }}/{{ $this->billableServers->count() + $this->excludedServers->count() }}</span>
            </summary>
            <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                @foreach ($this->billableServers as $server)
                    <li wire:key="billable-{{ $server->id }}" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-brand-ink">{{ $server->name }}</span>
                            <span class="mt-0.5 block truncate font-mono text-2xs text-brand-mist">{{ $server->providerDisplayLabel() }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-brand-moss">{{ __('Included in plan') }}</span>
                    </li>
                @endforeach
                @foreach ($this->excludedServers as $row)
                    <li wire:key="excluded-{{ $row['server']->id }}" class="flex flex-wrap items-center gap-3 px-3 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-brand-mist">{{ $row['server']->name }}</span>
                            <span class="mt-0.5 block truncate font-mono text-2xs text-brand-mist">{{ $row['server']->providerDisplayLabel() }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-brand-mist">{{ __('Not billed') }} — {{ $row['reason'] }}</span>
                    </li>
                @endforeach
            </ul>
        </details>

    </div>
</section>
