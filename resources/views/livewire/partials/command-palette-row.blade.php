{{--
    One palette row's inner content. Shared by the leaf (<a>) and nestable
    (<button>) wrappers in command-palette.blade.php.

    Props: $item (label/sublabel/icon), $i (flat index for active highlight),
    $isNest (true → drill-in chevron, false → open arrow on the active row).
--}}
<span
    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/50 text-brand-moss"
    :class="active === {{ $i }} ? 'bg-brand-sage/25 text-brand-forest' : ''"
>
    @switch($item['icon'])
        @case('server') <x-heroicon-o-server class="h-4 w-4" /> @break
        @case('globe-alt') <x-heroicon-o-globe-alt class="h-4 w-4" /> @break
        @case('rectangle-stack') <x-heroicon-o-rectangle-stack class="h-4 w-4" /> @break
        @case('signal') <x-heroicon-o-signal class="h-4 w-4" /> @break
        @case('circle-stack') <x-heroicon-o-circle-stack class="h-4 w-4" /> @break
        @case('building-office-2') <x-heroicon-o-building-office-2 class="h-4 w-4" /> @break
        @case('squares-2x2') <x-heroicon-o-squares-2x2 class="h-4 w-4" /> @break
        @case('plus-circle') <x-heroicon-o-plus-circle class="h-4 w-4" /> @break
        @case('cube') <x-heroicon-o-cube class="h-4 w-4" /> @break
        @case('bolt') <x-heroicon-o-bolt class="h-4 w-4" /> @break
        @case('share') <x-heroicon-o-share class="h-4 w-4" /> @break
        @case('arrows-right-left') <x-heroicon-o-arrows-right-left class="h-4 w-4" /> @break
        @case('heart') <x-heroicon-o-heart class="h-4 w-4" /> @break
        @case('rectangle-group') <x-heroicon-o-rectangle-group class="h-4 w-4" /> @break
        @case('document-text') <x-heroicon-o-document-text class="h-4 w-4" /> @break
        @case('bell') <x-heroicon-o-bell class="h-4 w-4" /> @break
        @case('user') <x-heroicon-o-user class="h-4 w-4" /> @break
        @case('shield-check') <x-heroicon-o-shield-check class="h-4 w-4" /> @break
        @case('key') <x-heroicon-o-key class="h-4 w-4" /> @break
        @case('command-line') <x-heroicon-o-command-line class="h-4 w-4" /> @break
        @case('code-bracket') <x-heroicon-o-code-bracket class="h-4 w-4" /> @break
        @case('credit-card') <x-heroicon-o-credit-card class="h-4 w-4" /> @break
        @case('cloud-arrow-down') <x-heroicon-o-cloud-arrow-down class="h-4 w-4" /> @break
        @case('cog-6-tooth') <x-heroicon-o-cog-6-tooth class="h-4 w-4" /> @break
        @case('wrench-screwdriver') <x-heroicon-o-wrench-screwdriver class="h-4 w-4" /> @break
        @default <x-heroicon-o-arrow-right class="h-4 w-4" />
    @endswitch
</span>
<span class="min-w-0 flex-1">
    <span class="block truncate font-medium">{{ $item['label'] }}</span>
    @if (! empty($item['sublabel']))
        <span class="block truncate text-xs text-brand-moss">{{ $item['sublabel'] }}</span>
    @endif
</span>
@if ($isNest)
    <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" />
@elseif (! empty($isAction))
    {{-- Action rows: a bolt affordance normally; when armed for confirmation
         (Alpine `confirming === i`) it flips to a "press ↵ again" hint. --}}
    <span
        x-show="confirming === {{ $i }}"
        x-cloak
        class="shrink-0 rounded bg-brand-forest/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest"
    >{{ __('↵ again') }}</span>
    <x-heroicon-o-bolt
        x-show="confirming !== {{ $i }}"
        class="h-3.5 w-3.5 shrink-0 text-brand-mist transition-opacity"
        x-bind:class="active === {{ $i }} ? 'opacity-100' : 'opacity-40'"
    />
@else
    {{-- Leaf "open" arrow: always present so every row carries a trailing
         affordance, brightened on the active row. --}}
    <x-heroicon-o-arrow-up-right
        class="h-3.5 w-3.5 shrink-0 text-brand-mist transition-opacity"
        x-bind:class="active === {{ $i }} ? 'opacity-100' : 'opacity-40'"
    />
@endif
