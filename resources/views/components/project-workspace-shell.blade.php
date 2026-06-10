@props([
    'workspace',
    /** @var string|null Active project section key (overview, resources, …) for nav highlight */
    'active' => null,
    /** @var bool When true, a small amber dot flags the Overview item. */
    'needsAttention' => false,
])

@php
    $card = 'dply-card overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';

    // Deterministic gradient + initials avatar from the project name — same
    // stable-hash scheme the server workspace sidebar uses, so a project gets a
    // consistent swatch with no external service.
    $avatarSeed = (string) ($workspace->name ?: $workspace->id);
    $avatarHash = hexdec(substr(sha1($avatarSeed), 0, 12));
    $avatarHueA = $avatarHash % 360;
    $avatarHueB = ($avatarHueA + 60 + ((int) (($avatarHash >> 4) % 120))) % 360;
    $avatarInitials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $avatarSeed) ?: 'P', 0, 2));
    $avatarStyle = "background-image: linear-gradient(135deg, hsl({$avatarHueA}deg 65% 56%) 0%, hsl({$avatarHueB}deg 65% 42%) 100%);";

    $nav = [
        ['key' => 'overview', 'label' => __('Overview'), 'icon' => 'chart-bar', 'href' => route('projects.overview', $workspace)],
        ['key' => 'resources', 'label' => __('Resources'), 'icon' => 'server-stack', 'href' => route('projects.resources', $workspace)],
        ['key' => 'access', 'label' => __('Access'), 'icon' => 'user-group', 'href' => route('projects.access', $workspace)],
        ['key' => 'operations', 'label' => __('Operations'), 'icon' => 'wrench-screwdriver', 'href' => route('projects.operations', $workspace)],
        ['key' => 'delivery', 'label' => __('Delivery'), 'icon' => 'rocket-launch', 'href' => route('projects.delivery', $workspace)],
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @isset($breadcrumb)
        <div class="mb-6">{{ $breadcrumb }}</div>
    @endisset

    <div class="lg:grid lg:gap-10 lg:grid-cols-12">
        {{-- Sidebar is hidden below lg; the layout renders a <select> switcher there instead. --}}
        <aside class="mb-8 hidden lg:col-span-3 lg:mb-0 lg:block">
            <div class="{{ $card }}">
                <div class="border-b border-brand-ink/10 p-4 sm:p-5">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-base font-semibold text-white shadow-sm ring-1 ring-brand-ink/10" style="{{ $avatarStyle }}">
                            {{ $avatarInitials }}
                        </span>
                        <div class="min-w-0 flex-1 leading-tight">
                            <p class="truncate text-base font-semibold text-brand-ink">{{ $workspace->name }}</p>
                            @if ($workspace->organization)
                                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $workspace->organization->name }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                <nav class="flex flex-col gap-0.5 p-2" aria-label="{{ __('Project sections') }}">
                    @foreach ($nav as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            @class([
                                $navLink,
                                'bg-brand-sand/60 text-brand-ink' => $active === $item['key'],
                                'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active !== $item['key'],
                            ])
                            @if ($active === $item['key']) aria-current="page" @endif
                        >
                            @switch($item['icon'])
                                @case('chart-bar')
                                    <x-heroicon-o-chart-bar class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('server-stack')
                                    <x-heroicon-o-server-stack class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('user-group')
                                    <x-heroicon-o-user-group class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('wrench-screwdriver')
                                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                                @case('rocket-launch')
                                    <x-heroicon-o-rocket-launch class="h-5 w-5 shrink-0 opacity-90" />
                                    @break
                            @endswitch
                            <span class="flex-1 truncate">{{ $item['label'] }}</span>
                            @if ($item['key'] === 'overview' && $needsAttention)
                                <span
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                    role="img"
                                    aria-label="{{ __('Needs attention') }}"
                                    title="{{ __('This project needs attention. Open Overview for details.') }}"
                                ></span>
                            @endif
                        </a>
                    @endforeach
                </nav>

                <div class="border-t border-brand-ink/10 p-3">
                    <a
                        href="{{ route('projects.index') }}"
                        wire:navigate
                        class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                    >
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                        {{ __('All projects') }}
                    </a>
                </div>
            </div>
        </aside>

        <div class="min-w-0 lg:col-span-9">
            <x-trial-pause-banner :organization="$workspace->organization" />
            {{ $slot }}
        </div>
    </div>
</div>
