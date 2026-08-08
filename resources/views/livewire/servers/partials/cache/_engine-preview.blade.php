{{--
    "What you get" preview for a cache engine that isn't installed yet.

    The install panel used to be a heading, a line of prose, and a button — it
    told you the mechanics of installing but nothing about what the workspace
    actually gives you afterwards. This previews the panels that appear once the
    engine is running, tailored per engine so Memcached doesn't advertise Redis
    features it doesn't have.

    Receives: $engine (key), $engineLabel.
--}}
@php
    $isRedisFork = in_array($engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true);

    // Panels the engine actually surfaces once installed. Memcached speaks a
    // different protocol, so it gets the honest short list.
    $previewPanels = $isRedisFork
        ? [
            ['icon' => 'heroicon-o-presentation-chart-line', 'title' => __('Live status'), 'body' => __('Service state, reachability probe, version, and bind address.')],
            ['icon' => 'heroicon-o-command-line', 'title' => __('Interactive console'), 'body' => __('Run :engine-cli over SSH — read-only by default, mutating behind an unlock.', ['engine' => strtolower($engineLabel)])],
            ['icon' => 'heroicon-o-chart-bar', 'title' => __('Keyspace dashboard'), 'body' => __('Memory, clients, ops/sec, and hit-rate sampled from INFO.')],
            ['icon' => 'heroicon-o-magnifying-glass', 'title' => __('Key browser'), 'body' => __('SCAN-based explorer that walks the keyspace without locking the engine.')],
            ['icon' => 'heroicon-o-signal', 'title' => __('Live MONITOR'), 'body' => __('Tail commands against the instance for a bounded window.')],
            ['icon' => 'heroicon-o-code-bracket-square', 'title' => __('Connection snippets'), 'body' => __('Ready-made Laravel, Node, Python, and Compose config.')],
        ]
        : [
            ['icon' => 'heroicon-o-presentation-chart-line', 'title' => __('Live status'), 'body' => __('Service state, reachability probe, version, and bind address.')],
            ['icon' => 'heroicon-o-chart-bar', 'title' => __('Live stats'), 'body' => __('Item count, memory, hit rate, and connection totals.')],
            ['icon' => 'heroicon-o-code-bracket-square', 'title' => __('Connection snippets'), 'body' => __('Ready-made Laravel, Node, Python, and Compose config.')],
            ['icon' => 'heroicon-o-cog-6-tooth', 'title' => __('Configure'), 'body' => __('Port, bind address, memory limit, and exposure rules.')],
        ];
@endphp

<div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('What you get') }}</p>
    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($previewPanels as $panel)
            <div class="flex items-start gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10">
                    <x-dynamic-component :component="$panel['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-brand-ink">{{ $panel['title'] }}</p>
                    <p class="mt-0.5 text-[11px] leading-relaxed text-brand-moss">{{ $panel['body'] }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>
