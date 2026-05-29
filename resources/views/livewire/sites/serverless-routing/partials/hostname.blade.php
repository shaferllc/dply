<section class="space-y-4">
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hostname') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge hostname & DNS') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('dply auto-provisions a subdomain on the testing domain pointing at this function. This is the hostname custom domains should CNAME to.') }}
                </p>
            </div>
        </div>
    </div>

    {{-- Reuse the DnsPanel component as-is — it already owns the provision/
         force-purge/verify flow for the auto-provisioned edge subdomain. --}}
    <livewire:serverless.dns-panel :site="$site" :wire:key="'dns-panel-routing-'.$site->id" />
</section>
