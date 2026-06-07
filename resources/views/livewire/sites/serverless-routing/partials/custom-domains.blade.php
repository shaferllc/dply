<section class="space-y-6">
    {{-- Primary endpoint: the dply-managed edge hostname custom domains CNAME
         to. Reuses the DnsPanel component (provision / verify / force-purge of
         the auto-provisioned testing-domain subdomain) — formerly its own
         "Hostname & DNS" tab, now the top of this merged Domains tab. --}}
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Primary endpoint') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge hostname & DNS') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('dply auto-provisions a subdomain on the testing domain pointing at this function. This is the hostname your custom domains should CNAME to.') }}
                </p>
            </div>
        </div>
    </div>

    <livewire:serverless.dns-panel :site="$site" :wire:key="'dns-panel-routing-'.$site->id" />

    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Domain') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Attach a custom domain') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Point your own hostname (e.g. api.acme.com) at this function. If dply manages DNS for the apex zone, the CNAME is written automatically. Otherwise dply gives you the exact CNAME target to publish at your own DNS provider, then verifies it.') }}
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
        <form wire:submit.prevent="addCustomDomain" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="flex-1 text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Hostname') }}</span>
                <input
                    type="text"
                    wire:model="newDomainHostname"
                    placeholder="api.acme.com"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                />
            </label>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="addCustomDomain"
                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-heroicon-o-plus class="h-4 w-4" />
                <span wire:loading.remove wire:target="addCustomDomain">{{ __('Attach domain') }}</span>
                <span wire:loading wire:target="addCustomDomain">{{ __('Attaching…') }}</span>
            </button>
        </form>
        </div>
    </div>

    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Domains') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Attached domains') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Each domain shows its provisioning mode and live DNS state. Manual-mode rows include the CNAME target you need to publish.') }}</p>
            </div>
            <span class="shrink-0 text-xs text-brand-moss">{{ trans_choice('{0} no custom domains|{1} :count domain|[2,*] :count domains', count($customDomains), ['count' => count($customDomains)]) }}</span>
        </div>

        <div class="px-6 py-6 sm:px-7">
        @if (empty($customDomains))
            <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                {{ __('No custom domains yet. Attach one above to route a hostname you control to this function.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($customDomains as $domain)
                    <li class="py-4" wire:key="domain-{{ $domain['hostname'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="font-mono text-sm text-brand-ink">{{ $domain['hostname'] }}</code>
                                    @php
                                        $status = $domain['dns_status'] ?? 'pending';
                                        $statusClasses = match ($status) {
                                            'ready' => 'bg-emerald-100 text-emerald-900',
                                            'failed' => 'bg-rose-100 text-rose-900',
                                            default => 'bg-amber-100 text-amber-900',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full {{ $statusClasses }} px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em]">{{ $status }}</span>
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ $domain['mode'] }}</span>
                                </div>
                                @if (($domain['mode'] ?? null) === 'manual' && ! empty($domain['cname_target']))
                                    <p class="mt-2 text-xs text-brand-moss">
                                        {{ __('Publish this CNAME at your DNS provider:') }}
                                        <code class="ml-1 font-mono text-brand-ink">{{ $domain['hostname'] }} → {{ $domain['cname_target'] }}</code>
                                    </p>
                                @elseif (! empty($domain['cname_target']))
                                    <p class="mt-2 text-xs text-brand-moss">
                                        {{ __('Pointed at:') }}
                                        <code class="ml-1 font-mono text-brand-ink">{{ $domain['cname_target'] }}</code>
                                    </p>
                                @endif
                                @if (! empty($domain['error']))
                                    <p class="mt-1 text-xs text-rose-700">{{ $domain['error'] }}</p>
                                @endif
                                @if (! empty($domain['verified_at']))
                                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Last checked:') }} {{ \Illuminate\Support\Carbon::parse($domain['verified_at'])->diffForHumans() }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                @if (($domain['mode'] ?? null) === 'manual')
                                    <button
                                        type="button"
                                        wire:click="verifyCustomDomain('{{ $domain['hostname'] }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="verifyCustomDomain('{{ $domain['hostname'] }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <x-heroicon-o-check-badge class="h-4 w-4" />
                                        {{ __('Verify') }}
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    wire:click="reprovisionCustomDomain('{{ $domain['hostname'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="reprovisionCustomDomain('{{ $domain['hostname'] }}')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                                >
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    {{ __('Re-provision') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="removeCustomDomain('{{ $domain['hostname'] }}')"
                                    wire:confirm="{{ __('Detach :host? Auto-mode DNS records will be removed automatically.', ['host' => $domain['hostname']]) }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                                >
                                    <x-heroicon-o-trash class="h-4 w-4" />
                                    {{ __('Detach') }}
                                </button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        </div>
    </div>

    <section class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-6 text-sm text-brand-moss">
        <p class="font-medium text-brand-ink">{{ __('TLS for custom domains') }}</p>
        <p class="mt-1">{{ __('Today the dply edge terminates TLS with a wildcard cert that only covers the testing domain. Custom domains rely on your upstream TLS (Cloudflare, ALB, etc.) until on-demand certs land in v1.2.') }}</p>
    </section>
</section>
