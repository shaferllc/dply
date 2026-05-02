@props(['active' => 'databases'])

@php
    $link = 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
@endphp

<nav class="flex flex-wrap gap-2 border-b border-brand-ink/10 pb-4 mb-8" aria-label="{{ __('Backups sections') }}">
    <a
        href="{{ route('backups.databases') }}"
        wire:navigate
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => $active === 'databases',
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== 'databases',
        ])
    >
        <x-heroicon-o-circle-stack class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
        {{ __('Databases') }}
    </a>
    <a
        href="{{ route('backups.files') }}"
        wire:navigate
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => $active === 'files',
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== 'files',
        ])
    >
        <x-heroicon-o-folder class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
        {{ __('Files') }}
    </a>
    <a
        href="{{ route('profile.backup-configurations') }}"
        wire:navigate
        @class([
            $link,
            'bg-brand-sand/60 text-brand-ink' => $active === 'storage',
            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== 'storage',
        ])
    >
        <x-heroicon-o-archive-box class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
        {{ __('Storage destinations') }}
    </a>
</nav>
