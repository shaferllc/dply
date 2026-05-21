<section class="space-y-4">
    <div class="dply-card p-6 sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Edge hostname & DNS') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('dply auto-provisions a subdomain on the testing domain pointing at this function. This is the hostname custom domains should CNAME to.') }}
        </p>
    </div>

    {{-- Reuse the DnsPanel component as-is — it already owns the provision/
         force-purge/verify flow for the auto-provisioned edge subdomain. --}}
    <livewire:serverless.dns-panel :site="$site" :wire:key="'dns-panel-routing-'.$site->id" />
</section>
