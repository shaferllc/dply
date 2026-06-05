@php
    $serverFlag = '--server '.$server->id;
    $installUrl = route('cli.install');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cli"
    :title="__('CLI')"
    :description="__('Install the dply CLI and run server and site operations from your terminal.')"
>
    @include('livewire.servers.partials.workspace-flashes')

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Terminal') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('dply CLI') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Manage this server from your terminal after a one-time `dply login`. Revoke CLI sessions under Profile → CLI.') }}
                </p>
            </div>
            <a
                href="{{ route('profile.cli') }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Install & login') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3" />
            </a>
        </div>

        <div class="grid gap-6 px-6 py-5 sm:grid-cols-2 sm:px-7">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Setup') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2.5 text-[11px] leading-relaxed text-brand-cream"><code>curl -fsSL {{ $installUrl }} | bash -s -- --login</code></pre>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Server') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2.5 text-[11px] leading-relaxed text-brand-cream"><code>dply server overview {{ $serverFlag }}
dply server sites list {{ $serverFlag }}
dply server ssh-keys list {{ $serverFlag }}</code></pre>
            </div>
        </div>

        <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-7">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('System users') }}</p>
            <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2.5 text-[11px] leading-relaxed text-brand-cream"><code>dply server system-users list {{ $serverFlag }}
dply server system-users sync {{ $serverFlag }}
dply server system-users add deployer {{ $serverFlag }} --web-group
dply server system-users update deployer {{ $serverFlag }} --sudo
dply server system-users remove deployer {{ $serverFlag }}</code></pre>
        </div>

        <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3 sm:px-7">
            <p class="text-[11px] leading-relaxed text-brand-moss">
                {{ __('Package served from this server at /cli/dply-cli.tgz. Mutations queue over SSH — same as this page.') }}
            </p>
        </div>
    </section>

</x-server-workspace-layout>
