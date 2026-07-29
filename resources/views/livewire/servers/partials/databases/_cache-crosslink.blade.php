@php
    $cachesInNav = collect(server_workspace_nav_for_server($server))->contains('key', 'caches');
@endphp
@if ($cachesInNav && ($opsReady ?? false))
    {{-- Nested strip inside the merged Databases card — not a floating callout. --}}
    <p class="border-b border-brand-ink/10 px-5 py-3 text-xs leading-relaxed text-brand-moss sm:px-6">
        {{ __('Redis, Valkey, and other cache engines live under Caches — separate from SQL here.') }}
        <a
            href="{{ route('servers.caches', ['server' => $server, 'tab' => 'redis']) }}"
            wire:navigate
            class="ml-1 font-semibold text-brand-forest hover:underline"
        >{{ __('Open Caches') }} →</a>
    </p>
@endif
