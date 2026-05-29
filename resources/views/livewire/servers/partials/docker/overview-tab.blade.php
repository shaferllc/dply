@php
    $btnRefresh = 'inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50';
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Engine') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('From the last inventory probe. Open Containers or Maintenance for live SSH data.') }}</p>
    </div>
    <dl class="grid gap-px bg-brand-ink/10 sm:grid-cols-2 lg:grid-cols-4">
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Version') }}</dt>
            <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $docker['version'] ?? __('Not detected') }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Running containers') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['containers_running'] ?? 0)) }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Stopped (exited)') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['containers_stopped'] ?? 0)) }}</dd>
        </div>
        <div class="bg-white px-5 py-4">
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Images') }}</dt>
            <dd class="mt-1 font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format((int) ($docker['images_count'] ?? 0)) }}</dd>
        </div>
    </dl>
    @if ($checkedAt)
        <p class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-7">
            {{ __('Last probed :time', ['time' => $checkedAt->diffForHumans()]) }}
        </p>
    @endif
</section>

@unless ($docker_present)
    <p class="mt-4 text-sm text-brand-moss">
        {{ __('Docker was not detected on the last probe. Install it from Manage → Tools, then refresh inventory.') }}
        <a href="{{ route('servers.manage', ['server' => $server, 'section' => 'tools']) }}" wire:navigate class="font-semibold text-brand-ink underline decoration-brand-gold/60 underline-offset-4">{{ __('Open Tools') }}</a>
    </p>
@endunless

<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ['tab' => 'containers', 'label' => __('Containers'), 'desc' => __('Start, stop, logs, inspect, remove')],
        ['tab' => 'images', 'label' => __('Images'), 'desc' => __('Pull, list, remove, prune dangling')],
        ['tab' => 'volumes', 'label' => __('Volumes'), 'desc' => __('Named volume inventory')],
        ['tab' => 'networks', 'label' => __('Networks'), 'desc' => __('Bridge, host, and overlay networks')],
        ['tab' => 'compose', 'label' => __('Compose'), 'desc' => __('Projects from docker compose ls')],
        ['tab' => 'maintenance', 'label' => __('Maintenance'), 'desc' => __('Disk usage and prune tools')],
    ] as $card)
        <button
            type="button"
            wire:click="setWorkspaceTab('{{ $card['tab'] }}')"
            class="rounded-2xl border border-brand-ink/10 bg-white p-4 text-left shadow-sm transition hover:border-brand-gold/40 hover:bg-brand-cream/30"
        >
            <p class="text-sm font-semibold text-brand-ink">{{ $card['label'] }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ $card['desc'] }}</p>
        </button>
    @endforeach
</div>
