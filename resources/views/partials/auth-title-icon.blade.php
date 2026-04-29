@php
    $v = $variant ?? 'default';
@endphp
<div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-gold/20 text-brand-forest ring-1 ring-brand-gold/30" aria-hidden="true">
    @switch($v)
        @case('login')
            <x-heroicon-o-arrow-right-end-on-rectangle class="h-6 w-6" />
            @break
        @case('register')
            <x-heroicon-o-user-plus class="h-6 w-6" />
            @break
        @case('forgot-password')
            <x-heroicon-o-envelope class="h-6 w-6" />
            @break
        @case('reset-password')
            <x-heroicon-o-key class="h-6 w-6" />
            @break
        @case('verify-email')
            <x-heroicon-o-check-circle class="h-6 w-6" />
            @break
        @case('confirm-password')
            <x-heroicon-o-lock-closed class="h-6 w-6" />
            @break
        @case('two-factor')
            <x-heroicon-o-device-tablet class="h-6 w-6" />
            @break
        @default
            <x-heroicon-o-user class="h-6 w-6" />
    @endswitch
</div>
