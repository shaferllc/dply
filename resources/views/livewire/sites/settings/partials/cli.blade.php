@php
    $cliBaseUrl = rtrim((string) config('app.url'), '/');
    $installUrl = route('cli.install');
    $siteFlag = '--site '.$site->id;
    $cliNestedInShell = (bool) ($cliNestedInShell ?? false);

    $localCommands = array_values(array_filter([
        ['label' => __('Install'), 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login --base-url '.$cliBaseUrl],
        ['label' => __('Link'), 'command' => 'dply link --byo '.$site->id],
        ['label' => __('Show'), 'command' => 'dply site show '.$siteFlag],
        ['label' => __('Status'), 'command' => 'dply site status '.$siteFlag],
        ['label' => __('Deploy'), 'command' => 'dply site deploy '.$siteFlag.' --follow'],
        ['label' => __('Deployments'), 'command' => 'dply site deployments '.$siteFlag],
        ['label' => __('Logs'), 'command' => 'dply site logs '.$siteFlag.' --follow'],
    ]));
@endphp

@if (workspace_surface_coming_soon('site_cli'))
    <x-cli-preview-panel :server="$site->server" />
@elseif ($cliNestedInShell)
    {{-- Console first; local install is one disclosure under it. --}}
    <div class="px-4 py-5 sm:px-6">
        @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
    </div>

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
        <x-cli-snippet
            :summary="__('Local terminal')"
            :intro="__('Install once, then run these from your machine. Sessions: Profile → CLI.')"
            :commands="$localCommands"
        />
        <p class="mt-3 font-mono text-xs text-brand-mist">
            {{ $site->id }}
            <span class="mx-1.5 text-brand-mist/50" aria-hidden="true">·</span>
            {{ $site->slug }}
        </p>
    </div>
@else
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-command-line"
            :title="__('CLI')"
            :note="__('Run commands against this site in the browser, or install the CLI locally.')"
        >
            <x-slot:actions>
                <a
                    href="{{ route('profile.cli') }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    {{ __('Install & login') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="px-4 py-5 sm:px-6">
            @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
        </div>

        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
            <x-cli-snippet
                :summary="__('Local terminal')"
                :intro="__('Install once, then run these from your machine. Sessions: Profile → CLI.')"
                :commands="$localCommands"
            />
        </div>
    </section>
@endif
