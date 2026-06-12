@props(['server', 'site'])

@php
    // Queue a fresh build straight off the box → staged to our download bucket for
    // 4h. Capped at config('quick_download.max_bytes') (250 MB); over that the
    // build fails and the requester is told to use a scheduled backup.
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
    {{-- Uses the shared <x-dropdown>, which teleports the menu to <body> with
         fixed positioning so it is never clipped by a `dply-card overflow-hidden`
         ancestor (table rows, stat cards) or stacked behind a sibling card. --}}
    <x-dropdown align="right" width="w-60" contentClasses="py-1">
        <x-slot name="trigger">
            <button
                type="button"
                title="{{ __('Prepare a fresh copy on the server and download it when ready — up to :cap.', ['cap' => $capLabel]) }}"
                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                <x-heroicon-m-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                {{ __('Quick download') }}
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
        </x-slot>
        <x-slot name="content">
            @foreach ($artifacts as $key => $label)
                <button
                    type="button"
                    wire:click="requestSiteQuickDownload('{{ $site->id }}', '{{ $key }}')"
                    class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-xs text-brand-ink transition hover:bg-brand-sand/40"
                >
                    <span>{{ $label }}</span>
                    <svg wire:loading wire:target="requestSiteQuickDownload('{{ $site->id }}', '{{ $key }}')" class="h-3.5 w-3.5 animate-spin text-brand-sage" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </button>
            @endforeach
            <p class="border-t border-brand-ink/10 px-3 pt-1.5 text-[11px] leading-snug text-brand-mist">
                {{ __('We build it on the server and notify you when it’s ready. Up to :cap; larger? Use a scheduled backup.', ['cap' => $capLabel]) }}
            </p>
        </x-slot>
    </x-dropdown>
@endif
