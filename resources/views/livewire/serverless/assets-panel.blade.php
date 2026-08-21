{{-- Embedded strip inside the Overview card — no card of its own. --}}
@php
    $bytes = function (int $value): string {
        if ($value <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) min(count($units) - 1, floor(log($value, 1024)));

        return round($value / (1024 ** $i), $i >= 3 ? 1 : 0).' '.$units[$i];
    };
@endphp

<div class="border-t border-brand-ink/10">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-photo"
        :title="__('Front-end assets')"
        :note="__('This function\'s built CSS and JS, published off the function and served from the edge.')"
    >
        @if ($isOver || $isWarn)
            <x-slot:actions>
                <span @class([
                    'inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold',
                    'bg-brand-gold/20 text-brand-ink' => $isWarn,
                    'bg-rose-100 text-rose-700' => $isOver,
                ])>
                    {{ $isOver ? __('Over allowance') : __('Approaching allowance') }}
                </span>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    <div class="space-y-4 px-4 py-3 sm:px-5">
        {{-- Where they're served from. --}}
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-sm">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="font-semibold text-brand-ink">{{ __('Served from') }}</span>
                @if ($assetUrl !== '')
                    <code class="min-w-0 break-all text-xs text-brand-ink">{{ $assetUrl }}</code>
                @else
                    <span class="text-brand-moss">{{ __('Nothing published yet — deploy the function.') }}</span>
                @endif
            </div>
            @if ($publishedAt !== '')
                <p class="mt-1 text-2xs text-brand-moss">
                    {{ __(':count file(s), published :when', [
                        'count' => number_format($fileCount),
                        'when' => \Illuminate\Support\Carbon::parse($publishedAt)->diffForHumans(),
                    ]) }}
                </p>
            @endif
            @unless ($cdnEnabled)
                <p class="mt-1 text-2xs text-brand-moss">
                    {{ __('Edge delivery is off for this environment, so assets are served through the function host.') }}
                </p>
            @endunless
        </div>

        {{-- Usage against the included allowance. --}}
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                ['label' => __('Storage'), 'used' => $status->storageBytes, 'cap' => $status->storageBytesCap, 'pct' => $status->storagePercent()],
                ['label' => __('Delivered this month'), 'used' => $status->bytesEgress, 'cap' => $status->bytesEgressCap, 'pct' => $status->egressPercent()],
            ] as $meter)
                <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-xs font-semibold text-brand-ink">{{ $meter['label'] }}</span>
                        <span class="text-2xs tabular-nums text-brand-moss">
                            {{ $bytes($meter['used']) }} / {{ $bytes($meter['cap']) }}
                        </span>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-ink/10">
                        <div @class([
                                'h-full rounded-full',
                                'bg-brand-forest' => $meter['pct'] < 80,
                                'bg-brand-gold' => $meter['pct'] >= 80 && $meter['pct'] < 100,
                                'bg-rose-500' => $meter['pct'] >= 100,
                            ])
                            style="width: {{ min(100, $meter['pct']) }}%"></div>
                    </div>
                    <p class="mt-1 text-2xs tabular-nums text-brand-moss">{{ $meter['pct'] }}%</p>
                </div>
            @endforeach
        </div>

        {{-- Say plainly what going over does. An advisory guardrail that reads
             like an outage is worse than no guardrail. --}}
        <p class="text-2xs text-brand-moss">
            @if ($isOver)
                {{ __('Assets keep serving normally. Usage past the included allowance is billed on your next invoice.') }}
            @else
                {{ __('Included with this function. Usage past the allowance is billed; delivery is never interrupted.') }}
            @endif
            @if ($measuredAt !== '')
                {{ __('Storage last measured :when.', ['when' => \Illuminate\Support\Carbon::parse($measuredAt)->diffForHumans()]) }}
            @endif
        </p>

        {{-- Custom asset domains. --}}
        <div class="space-y-2">
            <span class="block text-xs font-semibold text-brand-ink">{{ __('Custom asset domain') }}</span>

            @forelse ($domains as $domain)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    <div class="min-w-0">
                        <code class="block break-all text-xs text-brand-ink">{{ $domain['hostname'] }}</code>
                        @if (($domain['status'] ?? '') !== 'active')
                            <p class="mt-0.5 text-2xs text-brand-moss">
                                {{ __('Point a CNAME at :target', ['target' => $domain['origin'] ?? '']) }}
                            </p>
                        @endif
                        @if (! empty($domain['error']))
                            <p class="mt-0.5 text-2xs text-rose-600">{{ $domain['error'] }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-0.5 text-2xs font-semibold',
                            'bg-brand-forest/15 text-brand-forest' => ($domain['status'] ?? '') === 'active',
                            'bg-brand-gold/20 text-brand-ink' => ($domain['status'] ?? '') === 'pending',
                            'bg-rose-100 text-rose-700' => ($domain['status'] ?? '') === 'failed',
                        ])>
                            {{ ['pending' => __('Validating'), 'active' => __('Active'), 'failed' => __('Failed')][$domain['status'] ?? ''] ?? $domain['status'] ?? '' }}
                        </span>
                        @if (($domain['status'] ?? '') !== 'active')
                            <button type="button" wire:click="verifyDomain('{{ $domain['hostname'] }}')"
                                    wire:loading.attr="disabled"
                                    class="text-2xs font-semibold text-brand-forest hover:underline">
                                {{ __('Check') }}
                            </button>
                        @endif
                        <button type="button" wire:click="detachDomain('{{ $domain['hostname'] }}')"
                                wire:loading.attr="disabled"
                                class="text-2xs font-semibold text-brand-moss hover:text-rose-600 hover:underline">
                            {{ __('Remove') }}
                        </button>
                    </div>
                </div>
                @if (($domain['status'] ?? '') === 'active')
                    <p class="text-2xs text-brand-moss">
                        {{ __('Takes effect on the next deploy, when ASSET_URL is rewritten.') }}
                    </p>
                @endif
            @empty
                <p class="text-2xs text-brand-moss">
                    @if ($defaultHostname)
                        {{ __('Assets are served from :host. Attach your own hostname to serve them from your domain instead.', ['host' => $defaultHostname]) }}
                    @else
                        {{ __('Deploy the function once before attaching a custom asset domain.') }}
                    @endif
                </p>
            @endempty

            @if ($defaultHostname)
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" wire:model="newHostname" placeholder="cdn.example.com"
                           class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none" />
                    <button type="button" wire:click="attachDomain" wire:loading.attr="disabled" wire:target="attachDomain"
                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                        <span wire:loading.remove wire:target="attachDomain">{{ __('Attach') }}</span>
                        <span wire:loading wire:target="attachDomain">{{ __('Attaching…') }}</span>
                    </button>
                </div>
                @error('newHostname')
                    <p class="text-2xs text-rose-600">{{ $message }}</p>
                @enderror
            @endif
        </div>
    </div>
</div>
