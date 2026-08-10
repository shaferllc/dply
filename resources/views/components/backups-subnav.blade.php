@props(['active' => 'overview'])

<nav class="flex min-w-0 flex-1 gap-0.5 overflow-x-auto sm:gap-1" style="-webkit-overflow-scrolling: touch;" aria-label="{{ __('Backups sections') }}">
    {{-- /backups is the overview hub — it spans database dumps *and* file
         archives — so the deep views are what the other tabs point at. --}}
    @foreach ([
        ['key' => 'overview', 'route' => 'backups.databases', 'label' => __('Overview'), 'icon' => 'squares-2x2'],
        ['key' => 'files', 'route' => 'backups.files', 'label' => __('Files'), 'icon' => 'folder'],
        ['key' => 'storage', 'route' => 'profile.backup-configurations', 'label' => __('Storage'), 'icon' => 'archive-box'],
    ] as $item)
        @php $isActive = $active === $item['key']; @endphp
        <a
            href="{{ route($item['route']) }}"
            wire:navigate
            @class([
                'group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-semibold transition',
                'bg-brand-ink text-brand-cream' => $isActive,
                'text-brand-moss hover:bg-brand-sand/50 hover:text-brand-ink' => ! $isActive,
            ])
        >
            <x-dynamic-component
                :component="'heroicon-o-'.$item['icon']"
                @class([
                    'h-3.5 w-3.5 shrink-0',
                    'text-brand-cream' => $isActive,
                    'text-brand-mist group-hover:text-brand-ink' => ! $isActive,
                ])
                aria-hidden="true"
            />
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
