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
@endphp

@if (workspace_surface_coming_soon('site_cli'))
    <x-cli-preview-panel :server="$site->server" />
@else
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Terminal') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('dply CLI') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($isProductionMirror)
                        {{ __('Install the CLI, sign in to :host, then link this Production site. Mutations affect the live control plane.', ['host' => $productionConnection?->hostLabel() ?: 'Production']) }}
                    @else
                        {{ __('Manage this site from your terminal after a one-time `dply login`. Revoke CLI sessions under Profile → CLI.') }}
                    @endif
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
            <x-cli-snippet :summary="__('Setup')" :commands="array_values(array_filter([
                ['label' => __('Install'), 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login --base-url '.$cliBaseUrl],
                $isProductionMirror
                    ? ['label' => __('Login'), 'command' => 'dply login --base-url '.$cliBaseUrl]
                    : null,
                ['label' => __('Link'), 'command' => 'dply link --byo '.$site->id],
            ]))" />
            <x-cli-snippet :summary="__('Common commands')" :commands="[
                ['label' => __('Show'), 'command' => 'dply site show '.$siteFlag],
                ['label' => __('Status'), 'command' => 'dply site status '.$siteFlag],
                ['label' => __('Deploy'), 'command' => 'dply site deploy '.$siteFlag.' --follow'],
                ['label' => __('Deployments'), 'command' => 'dply site deployments '.$siteFlag],
                ['label' => __('Logs'), 'command' => 'dply site logs '.$siteFlag.' --follow'],
            ]" />
        </div>

        <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3 sm:px-7">
            <p class="font-mono text-[11px] text-brand-moss">
                {{ __('Site ID:') }} <span class="text-brand-ink">{{ $site->id }}</span>
                <span class="mx-2 text-brand-mist/50" aria-hidden="true">·</span>
                {{ __('Slug:') }} <span class="text-brand-ink">{{ $site->slug }}</span>
                @if ($isProductionMirror)
                    <span class="mx-2 text-brand-mist/50" aria-hidden="true">·</span>
                    {{ __('API:') }} <span class="text-brand-ink">{{ $cliBaseUrl }}</span>
                @endif
            </p>
        </div>
    </section>

    <section class="dply-card overflow-hidden p-0">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-7">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('In-browser') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('CLI console') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($isProductionMirror)
                        {{ __('Runs the real dply CLI against :host with your Production connection token.', ['host' => $productionConnection?->hostLabel() ?: 'Production']) }}
                    @else
                        {{ __('Run dply commands against this site without installing the CLI locally. Uses a short-lived session token.') }}
                    @endif
                </p>
            </div>
            @if ($isProductionMirror)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-950 ring-1 ring-inset ring-amber-200">
                    <x-heroicon-s-exclamation-triangle class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Production API') }}
                </span>
            @endif
        </div>
        <div class="bg-brand-sand/15 px-4 py-5 sm:px-7 sm:py-6">
            @livewire('sites.cli-console', ['site' => $site, 'server' => $server], key('cli-console-'.$site->id))
        </div>
    </section>
@endif
