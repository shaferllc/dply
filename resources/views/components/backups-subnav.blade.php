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
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615m0 0l-7.5-4.615a2.25 2.25 0 01-1.07-1.916V6.375"/></svg>
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
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
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
        <svg class="h-5 w-5 shrink-0 opacity-90" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
        {{ __('Storage destinations') }}
    </a>
</nav>
