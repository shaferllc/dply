<div class="grid gap-6 lg:grid-cols-2">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Security') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Security digest') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Auth failure counts, fail2ban jails, firewall posture, and sshd settings — lightweight read-only scan.') }}</p>
            </div>
        </div>
        <div class="px-6 py-5 text-sm sm:px-7">
            <a
                href="{{ route('servers.security-digest', $server) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
            >
                {{ __('Open security digest') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
            </a>
        </div>
    </section>

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-calendar-days class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploys') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deploy windows') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Server-wide deny windows that skip deploy jobs — check recent skips when investigating deploy gaps in logs.') }}</p>
            </div>
        </div>
        <div class="px-6 py-5 text-sm sm:px-7">
            <a
                href="{{ route('servers.deploy-policy', $server) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-xs font-semibold text-brand-moss hover:text-brand-ink"
            >
                {{ __('Open deploy windows') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
            </a>
        </div>
    </section>
</div>
