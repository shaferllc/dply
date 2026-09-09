{{--
    Lazy-load skeleton for Security digest. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and overview rows) — the dense head and the
    two-row stat strip, so the card does not resize when the real view swaps in.
--}}
<x-server-workspace-layout
    :server="$server"
    active="security-digest"
    :title="__('Security digest')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading security digest…') }}</span>

        <x-workspace-panel-head
            dense
            icon="heroicon-o-shield-exclamation"
            :title="__('Security')"
            :note="__('SSH auth failures, fail2ban jails, host firewall posture, and sshd hardening — a read-only digest over root SSH.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span class="inline-flex h-6 w-24 animate-pulse rounded-full bg-brand-ink/10" aria-hidden="true"></span>
                <span class="inline-flex h-6 w-28 animate-pulse rounded-md bg-brand-ink/10" aria-hidden="true"></span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Auth & fail2ban'), __('Hardening'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3" aria-hidden="true">
            @foreach (range(0, 5) as $i)
                <div @class([
                    'min-w-0 space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $i % 2 !== 0,
                    'sm:border-l' => $i % 3 !== 0,
                    'sm:border-l-0' => $i % 3 === 0,
                    'border-t' => $i >= 2,
                    'sm:border-t-0' => $i < 3,
                    'sm:border-t' => $i >= 3,
                ])>
                    <div class="h-2.5 w-24 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-3.5 w-12 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
