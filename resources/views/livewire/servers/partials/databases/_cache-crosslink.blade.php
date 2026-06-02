@php
    $cachesInNav = collect(server_workspace_nav_for_server($server))->contains('key', 'caches');
@endphp
@if ($cachesInNav && ($opsReady ?? false))
    <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/10 px-4 py-4 sm:px-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Need cache, not SQL?') }}</p>
                <p class="mt-1 max-w-xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Redis, Valkey, and other cache engines live in the Caches workspace — separate from relational databases here.') }}
                </p>
            </div>
            <a
                href="{{ route('servers.caches', ['server' => $server, 'tab' => 'redis']) }}"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
            >
                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                {{ __('Open Caches') }}
            </a>
        </div>
    </div>
@endif
