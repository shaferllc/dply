@props(['server', 'database'])

@php
    $supported = in_array((string) $database->engine, ['mysql', 'mariadb', 'postgres', 'sqlite', 'mongodb'], true);
    $capLabel = \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000));
@endphp

@if ($supported)
    <button
        type="button"
        wire:click="requestDatabaseQuickDownload('{{ $database->id }}')"
        title="{{ __('Prepare a fresh dump on the server and download it when ready — up to :cap.', ['cap' => $capLabel]) }}"
        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
    >
        <svg wire:loading wire:target="requestDatabaseQuickDownload('{{ $database->id }}')" class="h-4 w-4 animate-spin text-brand-sage" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <x-heroicon-m-arrow-down-tray wire:loading.remove wire:target="requestDatabaseQuickDownload('{{ $database->id }}')" class="h-4 w-4" aria-hidden="true" />
        {{ __('Download dump') }}
    </button>
@else
    <span class="text-xs text-brand-mist">{{ __('Dump not available for :engine', ['engine' => $database->engine]) }}</span>
@endif
