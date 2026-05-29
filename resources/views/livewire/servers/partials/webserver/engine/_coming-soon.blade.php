@php
    // Coming-soon teaser shown in place of the actionable engine panel for
    // engines flagged `coming_soon` in the catalog (and not yet the active
    // webserver). No switch / lifecycle controls — this is a preview only.
    $engineBlurb = match ($key) {
        'caddy' => __('Automatic HTTPS out of the box, simple Caddyfile syntax, HTTP/3 by default. Great for opinionated setups where you want sensible defaults over fine-grained tuning.'),
        'apache' => __('Battle-tested with the broadest module catalog and per-directory `.htaccess` support. Higher per-request footprint than nginx but unbeatable compatibility with legacy stacks.'),
        'openlitespeed' => __('LSAPI for the fastest PHP execution, built-in LSCache module with per-vhost cache rules, and a familiar Apache-style config. The standard pick for WordPress-heavy hosting.'),
        default => '',
    };

    $engineHighlights = match ($key) {
        'caddy' => [
            ['icon' => 'heroicon-o-lock-closed', 'title' => __('Automatic HTTPS'), 'body' => __('Certificates provisioned and renewed with zero config.')],
            ['icon' => 'heroicon-o-arrow-path-rounded-square', 'title' => __('Route inspector'), 'body' => __('Live routes, upstreams, and certs from the admin API.')],
            ['icon' => 'heroicon-o-code-bracket-square', 'title' => __('Caddyfile editor'), 'body' => __('In-app validate, format, and save with backups.')],
            ['icon' => 'heroicon-o-bolt', 'title' => __('HTTP/3 default'), 'body' => __('Modern protocol support without extra tuning.')],
        ],
        'apache' => [
            ['icon' => 'heroicon-o-puzzle-piece', 'title' => __('Module catalog'), 'body' => __('Browse and toggle loaded modules over SSH.')],
            ['icon' => 'heroicon-o-server-stack', 'title' => __('Vhost inspector'), 'body' => __('See every virtual host and its document root.')],
            ['icon' => 'heroicon-o-pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate apache2 config with backups.')],
            ['icon' => 'heroicon-o-lock-closed', 'title' => __('Certs & workers'), 'body' => __('Certificate inventory and MPM worker stats.')],
        ],
        'openlitespeed' => [
            ['icon' => 'heroicon-o-server-stack', 'title' => __('Vhosts & listeners'), 'body' => __('Inspect virtual hosts, listeners, and external apps.')],
            ['icon' => 'heroicon-o-bolt', 'title' => __('LSCache'), 'body' => __('Per-vhost cache rules for WordPress-heavy hosting.')],
            ['icon' => 'heroicon-o-cpu-chip', 'title' => __('LSAPI execution'), 'body' => __('The fastest PHP execution path, managed in-app.')],
            ['icon' => 'heroicon-o-pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate OLS config with backups.')],
        ],
        default => [],
    };
@endphp

@if ($engine_subtab !== 'info')
    <div class="{{ $card }} overflow-hidden">
        {{-- Header — engine icon + name + Coming soon badge --}}
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-dynamic-component :component="$info['icon']" class="h-5 w-5 text-brand-forest" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Engine') }}</p>
                    <h3 class="text-lg font-semibold text-brand-ink">{{ $info['label'] }}</h3>
                    <p class="mt-0.5 text-[12px] text-brand-moss">{{ __('Not yet available on this server.') }}</p>
                </div>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sand/70 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Coming soon') }}
            </span>
        </div>

        <div class="px-6 py-6 sm:px-8">
            @if ($engineBlurb !== '')
                <p class="max-w-prose text-sm leading-relaxed text-brand-moss">{{ $engineBlurb }}</p>
            @endif

            @if ($engineHighlights !== [])
                <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ($engineHighlights as $highlight)
                        <li class="flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03] sm:p-4">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                                <x-dynamic-component :component="$highlight['icon']" class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 text-left">
                                <span class="block text-sm font-semibold text-brand-ink">{{ $highlight['title'] }}</span>
                                <span class="mt-0.5 block text-[13px] leading-5 text-brand-moss">{{ $highlight['body'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-6 flex flex-col gap-3 border-t border-brand-ink/8 pt-5 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-brand-moss">
                    {{ __(':engine support is on the way — switching will land here when it ships.', ['engine' => $info['label']]) }}
                </p>
                <span class="inline-flex items-center justify-center gap-1.5 rounded-full bg-brand-ink/[0.04] px-3 py-1.5 text-xs font-medium text-brand-mist">
                    <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('In development') }}
                </span>
            </div>
        </div>
    </div>
@endif
