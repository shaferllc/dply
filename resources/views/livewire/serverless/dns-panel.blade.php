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
@endphp

<section class="dply-card p-6 sm:p-8 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('DNS & hostname') }}</p>
            <h2 class="mt-1 text-base font-bold text-brand-ink">{{ $host ?: __('No hostname yet') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Friendly hostname for the function. Resolves through the dply edge to the raw DigitalOcean Functions URL — DO Functions has no custom-domain support, so dply\'s app proxies the request.') }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $statusBadge }}">{{ $statusLabel }}</span>
            <button
                type="button"
                wire:click="provisionNow"
                wire:loading.attr="disabled"
                wire:target="provisionNow"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                title="{{ __('Re-run the DNS provisioner against this site. Idempotent — creates the record if missing, refreshes it otherwise.') }}"
            >
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="provisionNow" />
                <span wire:loading.remove wire:target="provisionNow">{{ __('Provision DNS now') }}</span>
                <span wire:loading wire:target="provisionNow">{{ __('Provisioning…') }}</span>
            </button>
        </div>
    </div>

    @if ($status === 'ready')
        @if ($coveredByWildcard)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                <p class="font-semibold">{{ __('Covered by wildcard') }}</p>
                <p class="mt-1">
                    {{ __('The zone has a `*` wildcard :type record resolving to', ['type' => $recordType]) }}
                    <span class="font-mono">{{ $recordData }}</span>.
                    {{ __('Every subdomain — including this function\'s hostname — resolves through it automatically. No per-site record needed.') }}
                </p>
            </div>
        @else
            <dl class="grid grid-cols-1 gap-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Zone') }}</dt>
                    <dd class="mt-1 font-mono text-brand-ink">{{ $zone ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Record') }}</dt>
                    <dd class="mt-1 font-mono text-brand-ink">{{ $recordName ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Type') }}</dt>
                    <dd class="mt-1 font-mono text-brand-ink">{{ $recordType ?: '—' }}</dd>
                </div>
                <div class="sm:col-span-1">
                    <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Target') }}</dt>
                    <dd class="mt-1 break-all font-mono text-brand-ink">{{ $recordData ?: '—' }}</dd>
                </div>
            </dl>
        @endif
        @if ($provisionedAt)
            <p class="text-xs text-brand-moss">
                {{ __('Provisioned :time. DNS changes can take a minute to propagate before the hostname starts resolving.', ['time' => \Illuminate\Support\Carbon::parse($provisionedAt)->diffForHumans()]) }}
            </p>
        @endif
    @elseif ($status === 'failed')
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 space-y-3">
            <div>
                <p class="font-semibold">{{ __('DNS provisioning failed') }}</p>
                <p class="mt-1 font-mono text-xs break-all">{{ $error !== '' ? $error : __('No error detail recorded.') }}</p>
            </div>

            @if (! empty($recordsAtName))
                <div>
                    <p class="font-semibold">{{ __('Existing records at this name (these are blocking the CNAME create):') }}</p>
                    <ul class="mt-1 divide-y divide-rose-200/60 rounded-lg border border-rose-200 bg-white">
                        @foreach ($recordsAtName as $r)
                            <li class="flex flex-wrap gap-3 px-3 py-2 font-mono text-[11px] text-brand-ink">
                                <span class="font-semibold">{{ $r['type'] ?? '?' }}</span>
                                <span>{{ $r['name'] ?? '?' }}</span>
                                <span class="text-brand-moss break-all">→ {{ $r['data'] ?? '?' }}</span>
                                <span class="ml-auto text-brand-mist">id #{{ $r['id'] ?? '?' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs">
                        {{ __('Force-purge deletes every record at this exact name in DigitalOcean, then re-runs the provisioner. Use when the standard purge can\'t see the conflict (e.g. a record created from DO\'s web UI with non-standard name formatting).') }}
                    </p>
                    <button
                        type="button"
                        wire:click="forcePurgeAndProvision"
                        wire:loading.attr="disabled"
                        wire:target="forcePurgeAndProvision"
                        wire:confirm="{{ __('This will permanently delete every DNS record at this exact name in DigitalOcean, then re-run the provisioner. Continue?') }}"
                        class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-rose-900 px-3 py-1.5 text-xs font-semibold text-rose-50 shadow-sm hover:bg-rose-950 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-trash class="h-3.5 w-3.5" wire:loading.class="animate-pulse" wire:target="forcePurgeAndProvision" />
                        <span wire:loading.remove wire:target="forcePurgeAndProvision">{{ __('Force-purge & retry') }}</span>
                        <span wire:loading wire:target="forcePurgeAndProvision">{{ __('Purging…') }}</span>
                    </button>
                </div>
            @endif

            <p class="text-xs">
                {{ __('Common causes: the token doesn\'t own the zone in DigitalOcean, the zone hasn\'t been created on DO yet, or a transient API error. Verify in the DigitalOcean dashboard, then retry.') }}
            </p>
        </div>
    @elseif ($status === 'skipped')
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('DNS provisioning skipped') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            @switch($reason)
                                @case('missing_token')
                                    {{ __('No DigitalOcean token configured. Set DIGITALOCEAN_TOKEN in dply\'s environment, then retry.') }}
                                    @break
                                @case('unconfigured_zone')
                                    {{ __('The hostname\'s zone isn\'t in DPLY_TESTING_DOMAINS. Add it (e.g. `dply.host`) to dply\'s environment, then retry.') }}
                                    @break
                                @default
                                    {{ __('See deploy log for details.') }}
                            @endswitch
                        </p>
                    </div>
                </div>
            </div>
        </section>
    @else
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 text-sm text-brand-moss">
            {{ __('DNS not provisioned yet. The next deploy will attempt it, or click "Provision DNS now" to run it immediately.') }}
        </div>
    @endif
</section>
