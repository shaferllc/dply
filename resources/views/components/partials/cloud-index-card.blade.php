@props([
    /** @var \App\Support\Cloud\CloudIndexRow $app */
    'app',
])

<li
    wire:key="cloud-{{ $app->id }}"
    class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-4 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:gap-6 {{ $app->isPreviewChild ? 'bg-brand-sand/10' : '' }}"
>
    <div class="flex min-w-0 flex-1 items-start gap-3 {{ $app->isPreviewChild ? 'sm:pl-6' : '' }}">
        @if ($app->isPreviewChild)
            <span class="mt-1 hidden select-none text-brand-mist sm:inline" aria-hidden="true">↳</span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($app->manageEnabled && $app->manageHref)
                    <a
                        href="{{ $app->manageHref }}"
                        wire:navigate
                        class="truncate text-sm font-semibold {{ $app->isPreviewChild ? 'text-brand-moss' : 'text-brand-ink' }} hover:text-brand-sage"
                    >{{ $app->name }}</a>
                @else
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $app->name }}</span>
                @endif
                @if ($app->previewBranch)
                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-indigo-700 ring-1 ring-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300 dark:ring-indigo-900/50">
                        @if ($app->previewPrNumber)
                            PR #{{ $app->previewPrNumber }}
                        @else
                            {{ __('Preview') }}
                        @endif
                    </span>
                @endif
                <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $app->statusBadgeClass }}">
                    <span class="relative inline-flex h-1.5 w-1.5">
                        @if ($app->statusPulse)
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-50"></span>
                        @endif
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-current"></span>
                    </span>
                    {{ $app->statusLabel }}
                </span>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-moss">
                @if ($app->liveUrl)
                    <a
                        href="{{ $app->liveUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex max-w-full items-center gap-1 truncate font-medium text-brand-sage hover:underline"
                        onclick="event.stopPropagation()"
                    >
                        <x-heroicon-m-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="truncate font-mono">{{ $app->hostname ?: $app->liveUrl }}</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1 text-brand-mist">
                        <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Live URL pending') }}
                    </span>
                @endif
                @if ($app->region)
                    <span aria-hidden="true" class="text-brand-mist/60">·</span>
                    <span class="inline-flex items-center gap-1 font-mono">
                        <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        {{ $app->region }}
                    </span>
                @endif
                @if ($app->sourceLabel)
                    <span aria-hidden="true" class="text-brand-mist/60">·</span>
                    <span class="inline-flex items-center gap-1 font-mono">
                        @if ($app->isSourceMode)
                            <x-heroicon-m-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        @else
                            <x-heroicon-m-cube class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        @endif
                        <span class="truncate">{{ $app->sourceLabel }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if ($app->manageEnabled && $app->manageHref)
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <a
                href="{{ $app->manageHref }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
            >
                {{ __('Open') }}
                <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            </a>
        </div>
    @endif
</li>
