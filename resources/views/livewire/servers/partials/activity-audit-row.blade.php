@php
    /**
     * Compact one-liner for an audit event (firewall or SSH keys). Renders the event token,
     * the actor (user email or "API"), the relative time, optional IP, and a copy-of-meta
     * tooltip. Caller passes `$event` (the audit model) and optional `$server` for the
     * date formatter.
     *
     * @var \Illuminate\Database\Eloquent\Model $event
     * @var \App\Models\Server|null $server
     */
    $when = isset($server)
        ? \App\Support\Servers\ServerDateFormatter::format($event->created_at, $server)
        : ($event->created_at?->toDayDateTimeString());
    $actor = $event->user?->name ?? $event->user?->email ?? __('API');
    $ip = $event->ip_address ?? null;
@endphp
<div class="flex flex-wrap items-start gap-x-3 gap-y-1 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 text-sm">
    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-sand/40 text-brand-moss ring-1 ring-brand-ink/10">
        <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
    </span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-xs text-brand-ink">{{ $event->event }}</span>
            <span class="text-[11px] text-brand-mist" title="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->diffForHumans() }}</span>
        </div>
        <p class="mt-0.5 text-[11px] text-brand-moss">
            {{ __('by :actor', ['actor' => $actor]) }}
            @if ($ip)
                <span class="ml-1 text-brand-mist">· {{ $ip }}</span>
            @endif
            @if ($when)
                <span class="ml-1 text-brand-mist">· {{ $when }}</span>
            @endif
        </p>
    </div>
</div>
