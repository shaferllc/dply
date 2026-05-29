@if ($report)
    <div class="dply-card flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5">
        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-sm text-brand-moss">
            <span class="font-semibold text-brand-ink">
                {{ __(':installed of :total installed', [
                    'installed' => $summary['installed_count'] ?? 0,
                    'total' => $summary['catalog_count'] ?? 0,
                ]) }}
            </span>
            @if ($overall === 'stale')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-900 ring-1 ring-amber-200">
                    {{ __('Probe stale — refresh recommended') }}
                </span>
            @elseif ($overall === 'blocked')
                <span class="text-xs text-brand-mist">{{ __('SSH not ready for installs') }}</span>
            @endif
            <span class="text-brand-mist" aria-hidden="true">·</span>
            <span class="text-xs">
                @if ($checkedAt)
                    {{ __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) }}
                @else
                    {{ __('Not probed yet') }}
                @endif
            </span>
            @if (($summary['runtime_versions'] ?? 0) > 0)
                <span class="text-brand-mist" aria-hidden="true">·</span>
                <span class="text-xs">
                    {{ trans_choice(':count runtime version|:count runtime versions', $summary['runtime_versions'] ?? 0, ['count' => $summary['runtime_versions'] ?? 0]) }}
                </span>
            @endif
        </div>
        @if ($opsReady && ! $isDeployer)
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($miseReprobePending)
                    <span class="inline-flex items-center gap-1.5 rounded-md border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1.5 text-xs font-medium text-brand-forest">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing probe…') }}
                    </span>
                @endif
                <button
                    type="button"
                    wire:click="refreshServerInventoryDetails"
                    wire:loading.attr="disabled"
                    wire:target="refreshServerInventoryDetails"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Refresh probe') }}
                    </span>
                    <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Refreshing…') }}
                    </span>
                </button>
            </div>
        @endif
    </div>

    <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('Related workspaces') }}">
        <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Also') }}</span>
        <a
            href="{{ route('servers.caches', $server) }}"
            wire:navigate
            class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
        >
            <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
            {{ __('Caches') }}
        </a>
        <a
            href="{{ route('servers.php', $server) }}"
            wire:navigate
            class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
        >
            <x-heroicon-o-command-line class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
            {{ __('PHP') }}
        </a>
        <a
            href="{{ route('servers.run', $server) }}"
            wire:navigate
            class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
        >
            <x-heroicon-o-play-circle class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
            {{ __('Run') }}
        </a>
        @feature('workspace.docker')
            <a
                href="{{ route('servers.docker', $server) }}"
                wire:navigate
                class="inline-flex items-center gap-1 rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
            >
                <x-heroicon-o-square-3-stack-3d class="h-3.5 w-3.5 text-brand-moss" aria-hidden="true" />
                {{ __('Docker') }}
            </a>
        @endfeature
    </nav>
@endif
