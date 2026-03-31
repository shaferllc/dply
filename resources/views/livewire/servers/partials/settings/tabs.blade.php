@php
    $tabs = config('server_settings.workspace_tabs', []);
@endphp

<nav
    class="mb-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-brand-ink/10 pb-px [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    aria-label="{{ __('Settings categories') }}"
>
    @foreach ($tabs as $slug => $meta)
        @php
            $active = $section === $slug;
            $danger = $slug === 'danger';
        @endphp
        <a
            href="{{ route('servers.settings', ['server' => $server, 'section' => $slug]) }}"
            wire:navigate
            @class([
                'shrink-0 whitespace-nowrap rounded-t-lg px-3 py-2.5 text-sm font-medium transition-colors -mb-px border-b-2',
                'border-brand-forest text-brand-ink' => $active && ! $danger,
                'border-red-700 text-red-900' => $active && $danger,
                'border-transparent text-brand-moss hover:border-brand-ink/15 hover:text-brand-ink' => ! $active && ! $danger,
                'border-transparent text-red-800/90 hover:border-red-200 hover:text-red-950' => ! $active && $danger,
            ])
        >{{ __($meta['label']) }}</a>
    @endforeach
</nav>
