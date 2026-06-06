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

        <div class="space-y-3 px-6 py-5 sm:px-7">
            <x-cli-snippet :summary="__('Setup')" :commands="[
                ['label' => __('Install'), 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login'],
            ]" />
            <x-cli-snippet :summary="__('Server')" :commands="[
                ['label' => __('Overview'), 'command' => 'dply server overview '.$serverFlag],
                ['label' => __('Sites'),    'command' => 'dply server sites list '.$serverFlag],
                ['label' => __('SSH keys'), 'command' => 'dply server ssh-keys list '.$serverFlag],
            ]" />
            <x-cli-snippet :summary="__('System users')" :commands="[
                ['label' => __('List'),   'command' => 'dply server system-users list '.$serverFlag],
                ['label' => __('Sync'),   'command' => 'dply server system-users sync '.$serverFlag],
                ['label' => __('Add'),    'command' => 'dply server system-users add deployer '.$serverFlag.' --web-group'],
                ['label' => __('Update'), 'command' => 'dply server system-users update deployer '.$serverFlag.' --sudo'],
                ['label' => __('Remove'), 'command' => 'dply server system-users remove deployer '.$serverFlag],
            ]" />
        </div>

        <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3 sm:px-7">
            <p class="text-[11px] leading-relaxed text-brand-moss">
                {{ __('Package served from this server at /cli/dply-cli.tgz. Mutations queue over SSH — same as this page.') }}
            </p>
        </div>
    </section>

</x-server-workspace-layout>
