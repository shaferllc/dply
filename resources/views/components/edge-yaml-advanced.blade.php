@props([
    'site',
    'file' => 'dply.yaml',
    'hasRepo' => false,
    'repoBadge' => null,
    'hint' => null,
])

<details {{ $attributes->class('group') }} @if ($hasRepo) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
        <span class="inline-flex items-center gap-2">
            {{ __('Advanced') }}
            @if ($hasRepo)
                <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ $repoBadge ?? __('Repo') }}
                </span>
            @endif
        </span>
        <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
    </summary>

    <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $file]) }}</p>
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
            >
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Generate :file', ['file' => $file]) }}
            </a>
        </div>

        @if (isset($status))
            {{ $status }}
        @elseif ($hasRepo)
            <p class="text-sm text-brand-moss">{{ __('Declared in :file. Dashboard values override after Save.', ['file' => $file]) }}</p>
        @else
            <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $file]) }}</p>
        @endif

        <x-edge-yaml-example :file="$file" :hint="$hint">
{{ $slot }}
        </x-edge-yaml-example>
    </div>
</details>
