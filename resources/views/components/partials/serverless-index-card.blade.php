@props([
    /** @var \App\Support\Serverless\ServerlessIndexRow $function */
    'function',
])

<li
    wire:key="serverless-{{ $function->id }}"
    class="flex flex-col gap-2 border-b border-brand-ink/10 px-5 py-3 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:gap-6"
>
    <div class="flex min-w-0 flex-1 items-start gap-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($function->manageEnabled && $function->manageHref)
                    <a
                        href="{{ $function->manageHref }}"
                        wire:navigate
                        class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage"
                    >{{ $function->name }}</a>
                @else
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $function->name }}</span>
                @endif
                <span class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-2xs font-semibold {{ $function->statusBadgeClass }}">
                    {{ $function->statusLabel }}
                </span>
            </div>

            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                <span class="inline-flex max-w-full items-center gap-1 truncate font-mono">
                    <x-heroicon-m-code-bracket class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span class="truncate">{{ $function->repositoryUrl ?? '—' }}</span>
                </span>
                @if ($function->runtimeLabel)
                    <span aria-hidden="true" class="text-brand-mist/60">·</span>
                    <span class="inline-flex items-center gap-1">
                        <x-heroicon-m-cpu-chip class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        {{ $function->runtimeLabel }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
        @if ($function->deployedLabel)
            <span class="me-1 text-xs text-brand-moss">{{ $function->deployedLabel }}</span>
        @endif
        @if ($function->journeyHref)
            <a
                href="{{ $function->journeyHref }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
            >
                {{ __('Journey') }}
            </a>
        @endif
        @if ($function->manageEnabled && $function->manageHref)
            <a
                href="{{ $function->manageHref }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
            >
                {{ __('Open') }}
                <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            </a>
        @endif
    </div>
</li>
