{{-- Domains tab — hairline strips inside the parent Routing card. --}}
@php
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

{{-- Primary edge hostname / DNS (DnsPanel owns provision + verify). --}}
<livewire:serverless.dns-panel :site="$site" :wire:key="'dns-panel-routing-'.$site->id" />

{{-- Attach custom domain --}}
<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Attach a custom domain') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Point your hostname at this function. dply writes the CNAME when it manages the zone; otherwise you publish the target and verify.') }}">
            {{ __('CNAME to the edge hostname · auto when dply manages DNS') }}
        </p>
    </div>
    <form wire:submit.prevent="addCustomDomain" class="{{ $panelPad }} flex flex-col gap-2 sm:flex-row sm:items-end">
        <label class="min-w-0 flex-1 text-sm">
            <span class="block text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hostname') }}</span>
            <input
                type="text"
                wire:model="newDomainHostname"
                placeholder="api.acme.com"
                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
            />
        </label>
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="addCustomDomain"
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
        >
            <x-heroicon-o-plus class="h-3.5 w-3.5" />
            <span wire:loading.remove wire:target="addCustomDomain">{{ __('Attach') }}</span>
            <span wire:loading wire:target="addCustomDomain">{{ __('Attaching…') }}</span>
        </button>
    </form>
</div>

{{-- Attached domains list --}}
<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Attached domains') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist">{{ __('Mode + live DNS state · manual rows show the CNAME target') }}</p>
        <span class="shrink-0 text-xs tabular-nums text-brand-moss">{{ trans_choice('{0} none|{1} :count domain|[2,*] :count domains', count($customDomains), ['count' => count($customDomains)]) }}</span>
    </div>

    @if (empty($customDomains))
        <div class="{{ $panelPad }} text-center text-xs text-brand-moss">
            {{ __('No custom domains yet. Attach one above to route a hostname you control.') }}
        </div>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($customDomains as $domain)
                <li class="{{ $panelPad }}" wire:key="domain-{{ $domain['hostname'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <code class="font-mono text-xs text-brand-ink">{{ $domain['hostname'] }}</code>
                                @php
                                    $status = $domain['dns_status'] ?? 'pending';
                                    $statusClasses = match ($status) {
                                        'ready' => 'bg-emerald-100 text-emerald-900',
                                        'failed' => 'bg-rose-100 text-rose-900',
                                        default => 'bg-amber-100 text-amber-900',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full {{ $statusClasses }} px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em]">{{ $status }}</span>
                                <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ $domain['mode'] }}</span>
                            </div>
                            @if (($domain['mode'] ?? null) === 'manual' && ! empty($domain['cname_target']))
                                <p class="mt-1 text-xs text-brand-moss">
                                    {{ __('Publish CNAME:') }}
                                    <code class="ml-1 font-mono text-brand-ink">{{ $domain['hostname'] }} → {{ $domain['cname_target'] }}</code>
                                </p>
                            @elseif (! empty($domain['cname_target']))
                                <p class="mt-1 text-xs text-brand-moss">
                                    {{ __('Pointed at:') }}
                                    <code class="ml-1 font-mono text-brand-ink">{{ $domain['cname_target'] }}</code>
                                </p>
                            @endif
                            @if (! empty($domain['error']))
                                <p class="mt-1 text-xs text-rose-700">{{ $domain['error'] }}</p>
                            @endif
                            @if (! empty($domain['verified_at']))
                                <p class="mt-0.5 text-2xs text-brand-mist">{{ __('Last checked:') }} {{ \Illuminate\Support\Carbon::parse($domain['verified_at'])->diffForHumans() }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            @if (($domain['mode'] ?? null) === 'manual')
                                <button
                                    type="button"
                                    wire:click="verifyCustomDomain('{{ $domain['hostname'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="verifyCustomDomain('{{ $domain['hostname'] }}')"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <x-heroicon-o-check-badge class="h-3.5 w-3.5" />
                                    {{ __('Verify') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="reprovisionCustomDomain('{{ $domain['hostname'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="reprovisionCustomDomain('{{ $domain['hostname'] }}')"
                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            >
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                {{ __('Re-provision') }}
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeCustomDomain', @js([$domain['hostname']]), @js(__('Detach domain')), @js(__('Detach :host? Auto-mode DNS records will be removed automatically.', ['host' => $domain['hostname']])), @js(__('Detach')), true)"
                                class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2 py-1 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                {{ __('Detach') }}
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>

<div class="{{ $panelPad }} bg-brand-sand/15 text-xs leading-relaxed text-brand-moss">
    <p class="font-semibold text-brand-ink">{{ __('TLS for custom domains') }}</p>
    <p class="mt-0.5">{{ __('Today the dply edge terminates TLS with a wildcard that covers the testing domain. Custom domains rely on upstream TLS (Cloudflare, ALB, etc.) until on-demand certs land.') }}</p>
</div>
