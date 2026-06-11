@props(['server', 'database'])

@php
    $supported = in_array((string) $database->engine, ['mysql', 'mariadb', 'postgres', 'sqlite', 'mongodb'], true);
    $capLabel = \Illuminate\Support\Number::fileSize((int) config('quick_download.max_bytes', 262_144_000));
@endphp

@if ($supported)
    <a
        href="{{ route('servers.databases.quick-dump', [$server, $database]) }}"
        title="{{ __('Stream a fresh dump straight from the server, up to :cap. No S3.', ['cap' => $capLabel]) }}"
        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
    >
        <x-heroicon-m-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
        {{ __('Download dump') }}
    </a>
@else
    <span class="text-xs text-brand-mist">{{ __('Dump not available for :engine', ['engine' => $database->engine]) }}</span>
@endif
