{{-- Expects $server available; same copy across server workspace areas when SSH/provision is not ready. --}}
<div class="rounded-2xl border border-brand-gold/40 bg-brand-sand/40 px-5 py-4 text-sm text-brand-olive">
    <p>{{ __('Provisioning and SSH must be ready before you can use this section.') }}</p>
    <div class="mt-3">
        <a
            href="{{ route('servers.settings', ['server' => $server, 'section' => 'connection']) }}"
            wire:navigate
            class="font-medium text-brand-olive underline decoration-brand-gold/60 underline-offset-4"
        >
            {{ __('Open connection settings') }}
        </a>
    </div>
</div>
