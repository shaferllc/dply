@props([
    'current' => 1,
    'reached' => 1,
    'mode' => 'provider',
    'hostKind' => 'vm',
])
@php
    $skipsStep3 = $mode === 'custom' && $hostKind === 'docker';
    $whereLabel = $mode === 'custom' ? __('Connection') : __('Where it runs');
    $steps = [
        ['n' => 1, 'label' => __('Type & name'), 'route' => 'servers.create',         'params' => ['edit' => 1]],
        ['n' => 2, 'label' => $whereLabel,       'route' => 'servers.create.where',   'params' => []],
        ['n' => 3, 'label' => __('What it runs'),'route' => 'servers.create.what',    'params' => []],
        ['n' => 4, 'label' => __('Review'),      'route' => 'servers.create.review',  'params' => []],
    ];
@endphp
<nav aria-label="{{ __('Create server progress') }}" class="mb-6">
    <ol class="flex flex-wrap items-center gap-2 sm:gap-3">
        @foreach ($steps as $step)
            @php
                $n = $step['n'];
                $isCurrent = $n === $current;
                $isReached = $n <= $reached;
                $isSkipped = $n === 3 && $skipsStep3;
                $isClickable = $isReached && ! $isCurrent && ! $isSkipped;
                $stateClasses = match (true) {
                    $isCurrent => 'border-sky-500 bg-sky-50 text-sky-900',
                    $isSkipped => 'border-zinc-200 bg-zinc-50 text-zinc-400 line-through',
                    $isReached => 'border-emerald-300 bg-emerald-50 text-emerald-900',
                    default    => 'border-zinc-200 bg-white text-brand-mist',
                };
                $bubbleClasses = match (true) {
                    $isCurrent => 'border-sky-500 bg-white text-sky-700',
                    $isSkipped => 'border-zinc-200 bg-white text-zinc-400',
                    $isReached => 'border-emerald-400 bg-emerald-500 text-white',
                    default    => 'border-zinc-200 bg-white text-brand-mist',
                };
            @endphp
            <li class="flex items-center gap-2 sm:gap-3">
                @if ($isClickable)
                    <a href="{{ route($step['route'], $step['params'] ?? []) }}" wire:navigate class="group flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors hover:border-emerald-400 hover:bg-emerald-100 sm:text-sm {{ $stateClasses }}">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border text-[11px] {{ $bubbleClasses }}">
                            @if ($isReached && ! $isCurrent && ! $isSkipped)
                                <x-heroicon-o-check class="h-3.5 w-3.5" />
                            @else
                                {{ $n }}
                            @endif
                        </span>
                        <span>{{ $step['label'] }}</span>
                    </a>
                @else
                    <span aria-current="{{ $isCurrent ? 'step' : 'false' }}" class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold sm:text-sm {{ $stateClasses }}">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border text-[11px] {{ $bubbleClasses }}">
                            @if ($isReached && ! $isCurrent && ! $isSkipped)
                                <x-heroicon-o-check class="h-3.5 w-3.5" />
                            @else
                                {{ $n }}
                            @endif
                        </span>
                        <span>{{ $isSkipped ? __(':label (skipped)', ['label' => $step['label']]) : $step['label'] }}</span>
                    </span>
                @endif
                @if (! $loop->last)
                    <x-heroicon-o-chevron-right class="hidden h-4 w-4 text-brand-mist sm:block" />
                @endif
            </li>
        @endforeach
    </ol>
</nav>
