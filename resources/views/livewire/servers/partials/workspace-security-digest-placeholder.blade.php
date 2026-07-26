{{--
    Lazy-load skeleton for Security digest. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and overview rows).
--}}
<x-server-workspace-layout
    :server="$server"
    active="security-digest"
    :title="__('Security digest')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading security digest…') }}</span>

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Security') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('SSH auth failures, fail2ban jails, host firewall posture, and sshd hardening — a read-only digest over root SSH.') }}
                        </p>
                    </div>
                </div>
                <span class="inline-flex h-7 w-24 animate-pulse rounded-full bg-brand-ink/10"></span>
            </div>
        </div>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            @foreach ([__('Overview'), __('Auth & fail2ban'), __('Hardening'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex items-start gap-3">
                <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            </div>
        </div>

        <div class="grid gap-3 px-5 py-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-4" aria-hidden="true">
            @foreach (range(1, 4) as $tile)
                <div class="h-24 animate-pulse rounded-2xl bg-brand-ink/10"></div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
