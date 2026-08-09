{{-- Legacy hostname tab — folded into Domains; kept for any stale include. --}}
@php
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1">
        <h3 class="flex shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            {{ __('Edge hostname & DNS') }}
        </h3>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist">
            {{ __('Auto-provisioned testing-domain subdomain — CNAME target for custom domains') }}
        </p>
    </div>
</div>

<livewire:serverless.dns-panel :site="$site" :wire:key="'dns-panel-routing-'.$site->id" />
