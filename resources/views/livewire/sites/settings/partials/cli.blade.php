@php
    $isProductionMirror = data_get($site->meta, 'production_data_mirror') === true
        && production_data_mirror_connected();
    $productionConnection = $isProductionMirror
        ? app(\App\Services\ProductionData\ProductionDataMirror::class)->connectionFor(auth()->user())
        : null;
    $cliBaseUrl = rtrim((string) ($productionConnection?->base_url ?: config('app.url')), '/');
    $installUrl = $isProductionMirror
        ? $cliBaseUrl.'/cli/install.sh'
        : route('cli.install');
    $siteFlag = '--site '.$site->id;
    $cliNestedInShell = (bool) ($cliNestedInShell ?? false);

    $localCommands = array_values(array_filter([
        ['label' => __('Install'), 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login --base-url '.$cliBaseUrl],
        $isProductionMirror
            ? ['label' => __('Login'), 'command' => 'dply login --base-url '.$cliBaseUrl]
            : null,
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
        @if ($isProductionMirror)
            <div class="mb-3 flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-950 ring-1 ring-inset ring-amber-200">
                    <x-heroicon-s-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Production API — :host', ['host' => $productionConnection?->hostLabel() ?: 'Production']) }}
                </span>
            </div>
        @endif

        @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
    </div>

    <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
        <x-cli-snippet
            :summary="__('Local terminal')"
            :intro="__('Install once, then run these from your machine. Sessions: Profile → CLI.')"
            :commands="$localCommands"
        />
        <p class="mt-3 font-mono text-[11px] text-brand-mist">
            {{ $site->id }}
            <span class="mx-1.5 text-brand-mist/50" aria-hidden="true">·</span>
            {{ $site->slug }}
            @if ($isProductionMirror)
                <span class="mx-1.5 text-brand-mist/50" aria-hidden="true">·</span>
                {{ $cliBaseUrl }}
            @endif
        </p>
    </div>
@else
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('CLI') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Run commands against this site in the browser, or install the CLI locally.') }}
                        </p>
                    </div>
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
        </div>

        <div class="px-4 py-5 sm:px-6">
            @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
        </div>

        <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <x-cli-snippet
                :summary="__('Local terminal')"
                :intro="__('Install once, then run these from your machine. Sessions: Profile → CLI.')"
                :commands="$localCommands"
            />
        </div>
    </section>
@endif
