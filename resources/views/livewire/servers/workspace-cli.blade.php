@php
    $serverArg = $server->id;
    $installUrl = route('cli.install');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cli"
    :title="__('CLI')"
    :description="__('Install the dply CLI and drive this server from your terminal.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            icon="heroicon-o-command-line"
            :title="__('CLI')"
            :note="__('One-time `dply login`, then the same operations as this workspace — scoped to your org.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <a
                    href="{{ route('profile.cli') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sand/40"
                >
                    {{ __('Sessions') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="space-y-2.5 px-5 py-4 sm:px-6">
            <x-cli-snippet :summary="__('Setup')" size="10" :commands="[
                ['label' => __('Install'), 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login'],
                ['label' => __('Login'),   'command' => 'dply login'],
                ['label' => __('Session'), 'command' => 'dply whoami'],
            ]" />

            <x-cli-snippet open :summary="__('This server')" size="10" :commands="[
                ['label' => __('List'),         'command' => 'dply servers:list'],
                ['label' => __('Run'),          'command' => 'dply servers:run '.$serverArg.' -- uptime'],
                ['label' => __('Firewall'),     'command' => 'dply servers:firewall '.$serverArg],
                ['label' => __('Apply rules'),  'command' => 'dply servers:firewall '.$serverArg.' --apply'],
                ['label' => __('Log shipping'), 'command' => 'dply servers:log-shipping '.$serverArg.' --enable'],
            ]" />

            <x-cli-snippet :summary="__('Sites on this server')" size="10" :commands="[
                ['label' => __('List'),        'command' => 'dply sites:list'],
                ['label' => __('Show'),        'command' => 'dply sites:show <site>'],
                ['label' => __('Deploy'),      'command' => 'dply sites:deploy <site>'],
                ['label' => __('Deployments'), 'command' => 'dply sites:deployments <site>'],
                ['label' => __('Errors'),      'command' => 'dply sites:errors <site>'],
            ]" />
        </div>

        <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-5 py-3 sm:px-6">
            <p class="text-[11px] leading-relaxed text-brand-moss">
                {{ __('Add `--json` to any read command to pipe it. Revoke CLI sessions under Profile → CLI.') }}
                <span class="mx-1 text-brand-mist/50" aria-hidden="true">·</span>
                <span class="font-mono text-brand-mist">{{ $server->id }}</span>
            </p>
        </div>
    </section>
</x-server-workspace-layout>
