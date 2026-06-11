@props(['server', 'site'])

@php
    // Live stream straight from the box — no S3, no stored artifact. Capped to
    // config('quick_download.max_bytes') (250 MB); over that the request 413s.
    $artifacts = [
        'bundle' => __('Everything (files + DB + .env)'),
        'files'  => __('Site files (.tar.gz)'),
        'env'    => __('.env file'),
        'vhost'  => __('Webserver config'),
        'logs'   => __('Logs (.tar.gz)'),
        'home'   => __('Full home directory'),
    ];
    $capLabel = \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000));
@endphp

@if ($site->supportsSshFileArchive())
    <div x-data="{ open: false }" class="relative inline-block text-left" wire:ignore>
        <button
            type="button"
            @click="open = ! open"
            @click.outside="open = false"
            title="{{ __('Stream a fresh copy straight from the server, up to :cap. No S3.', ['cap' => $capLabel]) }}"
            class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
        >
            <x-heroicon-m-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
            {{ __('Quick download') }}
            <x-heroicon-m-chevron-down class="h-3.5 w-3.5" aria-hidden="true" />
        </button>

        <div
            x-show="open"
            x-transition
            style="display: none"
            class="absolute right-0 z-30 mt-1 w-60 overflow-hidden rounded-lg border border-brand-ink/10 bg-white py-1 shadow-lg"
        >
            @foreach ($artifacts as $key => $label)
                <a
                    href="{{ route('sites.quick-download', [$server, $site, $key]) }}"
                    @click="open = false"
                    class="block px-3 py-1.5 text-xs text-brand-ink transition hover:bg-brand-sand/40"
                >
                    {{ $label }}
                </a>
            @endforeach
            <p class="border-t border-brand-ink/10 px-3 pt-1.5 text-[11px] leading-snug text-brand-mist">
                {{ __('Live stream, up to :cap. Larger? Use a scheduled backup.', ['cap' => $capLabel]) }}
            </p>
        </div>
    </div>
@endif
