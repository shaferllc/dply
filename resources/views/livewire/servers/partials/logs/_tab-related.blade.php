{{-- Two link-out strips, not two half-empty cards: the "SECURITY" / "DEPLOYS"
     eyebrows restated the titles under them, and each body held a single link
     that now rides in the head's actions slot. --}}
<div class="grid gap-px bg-brand-ink/10 lg:grid-cols-2">
    <x-workspace-panel-head
        icon="heroicon-o-shield-exclamation"
        :title="__('Security digest')"
        :note="__('Auth failure counts, fail2ban jails, firewall posture, and sshd settings — lightweight read-only scan.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('servers.security-digest', $server) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Open') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    <x-workspace-panel-head
        icon="heroicon-o-calendar-days"
        :title="__('Deploy windows')"
        :note="__('Server-wide deny windows that skip deploy jobs — check recent skips when investigating deploy gaps in logs.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('servers.deploys', ['server' => $server, 'tab' => 'deploy-windows']) }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Open') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>
</div>
