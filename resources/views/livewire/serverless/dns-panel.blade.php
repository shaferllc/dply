{{-- Embedded DNS strip for serverless proxy-routing (no nested outer card). --}}
@php
    $statusBadge = match ($status) {
        'ready' => 'bg-emerald-100 text-emerald-900',
        'failed' => 'bg-rose-100 text-rose-900',
        'skipped' => 'bg-amber-100 text-amber-900',
        default => 'bg-slate-100 text-slate-700',
    };
    $statusLabel = match ($status) {
        'ready' => __('Live'),
        'failed' => __('Failed'),
        'skipped' => __('Skipped'),
        default => __('Pending'),
    };
    $panelPad = 'px-3 py-2.5 sm:px-4';
    $stripHead = 'border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4';
@endphp

<div class="contents">
<div class="border-b border-brand-ink/10">
    <div class="{{ $stripHead }} flex flex-wrap items-center gap-x-2 gap-y-1.5">
        <h3 class="flex min-w-0 shrink-0 items-center gap-1.5 text-sm font-semibold text-brand-ink">
            <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
            <span class="truncate">{{ $host ?: __('No hostname yet') }}</span>
        </h3>
        <span class="inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-2xs font-semibold {{ $statusBadge }}">{{ $statusLabel }}</span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 truncate text-xs text-brand-mist" title="{{ __('Friendly hostname via the dply edge — DO Functions has no custom-domain support.') }}">
            {{ __('Edge hostname · CNAME target for custom domains') }}
        </p>
        <button
            type="button"
            wire:click="provisionNow"
            wire:loading.attr="disabled"
            wire:target="provisionNow"
            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
            title="{{ __('Re-run the DNS provisioner. Idempotent.') }}"
        >
            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="provisionNow" />
            <span wire:loading.remove wire:target="provisionNow">{{ __('Provision DNS') }}</span>
            <span wire:loading wire:target="provisionNow">{{ __('Provisioning…') }}</span>
        </button>
    </div>

    <div class="{{ $panelPad }} space-y-2.5">
        @if ($status === 'ready')
            @if ($coveredByWildcard)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-xs text-emerald-950">
                    <p class="font-semibold">{{ __('Covered by wildcard') }}</p>
                    <p class="mt-0.5">
                        {{ __('The zone has a `*` wildcard :type record resolving to', ['type' => $recordType]) }}
                        <span class="font-mono">{{ $recordData }}</span>.
                        {{ __('No per-site record needed.') }}
                    </p>
                </div>
            @else
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs sm:grid-cols-4">
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Zone') }}</dt>
                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $zone ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Record') }}</dt>
                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $recordName ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</dt>
                        <dd class="mt-0.5 font-mono text-brand-ink">{{ $recordType ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Target') }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-brand-ink">{{ $recordData ?: '—' }}</dd>
                    </div>
                </dl>
            @endif
            @if ($provisionedAt)
                <p class="text-xs text-brand-moss">
                    {{ __('Provisioned :time. Propagation can take a minute.', ['time' => \Illuminate\Support\Carbon::parse($provisionedAt)->diffForHumans()]) }}
                </p>
            @endif
        @elseif ($status === 'failed')
            <div class="space-y-2 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-xs text-rose-900">
                <div>
                    <p class="font-semibold">{{ __('DNS provisioning failed') }}</p>
                    <p class="mt-0.5 break-all font-mono text-xs">{{ $error !== '' ? $error : __('No error detail recorded.') }}</p>
                </div>

                @if (! empty($recordsAtName))
                    <div>
                        <p class="font-semibold">{{ __('Existing records at this name (blocking CNAME create):') }}</p>
                        <ul class="mt-1 divide-y divide-rose-200/60 rounded-lg border border-rose-200 bg-white">
                            @foreach ($recordsAtName as $r)
                                <li class="flex flex-wrap gap-2 px-2.5 py-1.5 font-mono text-2xs text-brand-ink">
                                    <span class="font-semibold">{{ $r['type'] ?? '?' }}</span>
                                    <span>{{ $r['name'] ?? '?' }}</span>
                                    <span class="break-all text-brand-moss">→ {{ $r['data'] ?? '?' }}</span>
                                    <span class="ml-auto text-brand-mist">id #{{ $r['id'] ?? '?' }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-1.5 text-xs">
                            {{ __('Force-purge deletes every record at this exact name in DigitalOcean, then re-runs the provisioner.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('forcePurgeAndProvision', [], @js(__('Force-purge DNS records?')), @js(__('This permanently deletes every DNS record at this exact name in DigitalOcean, then re-runs the provisioner.')), @js(__('Force-purge & retry')), true)"
                            wire:loading.attr="disabled"
                            wire:target="forcePurgeAndProvision,confirmActionModal"
                            class="mt-1.5 inline-flex items-center gap-1 rounded-lg bg-rose-900 px-2 py-1 text-xs font-semibold text-rose-50 shadow-sm hover:bg-rose-950 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5" wire:loading.class="animate-pulse" wire:target="forcePurgeAndProvision,confirmActionModal" />
                            <span wire:loading.remove wire:target="forcePurgeAndProvision,confirmActionModal">{{ __('Force-purge & retry') }}</span>
                            <span wire:loading wire:target="forcePurgeAndProvision,confirmActionModal">{{ __('Purging…') }}</span>
                        </button>
                    </div>
                @endif

                <p class="text-xs">
                    {{ __("Common causes: token doesn't own the zone, zone missing on DO, or a transient API error.") }}
                </p>
            </div>
        @elseif ($status === 'skipped')
            <div class="rounded-lg border border-amber-200 bg-amber-50/60 px-2.5 py-2 text-xs text-brand-moss">
                <p class="font-semibold text-brand-ink">{{ __('DNS provisioning skipped') }}</p>
                <p class="mt-0.5">
                    @switch($reason)
                        @case('missing_token')
                            {{ __('No DigitalOcean token configured. Set DIGITALOCEAN_TOKEN, then retry.') }}
                            @break
                        @case('unconfigured_zone')
                            {{ __('Hostname zone isn’t in DPLY_TESTING_DOMAINS. Add it, then retry.') }}
                            @break
                        @default
                            {{ __('See deploy log for details.') }}
                    @endswitch
                </p>
            </div>
        @else
            <p class="text-xs text-brand-moss">
                {{ __('DNS not provisioned yet. The next deploy will attempt it, or click Provision DNS.') }}
            </p>
        @endif
    </div>
</div>

@include('livewire.partials.confirm-action-modal')
</div>
