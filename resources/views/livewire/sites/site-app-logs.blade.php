{{-- dply Logs "App logs" — received application log records for this site. --}}
@php
    $levelColors = [
        'emergency' => 'bg-rose-100 text-rose-800', 'alert' => 'bg-rose-100 text-rose-800',
        'critical' => 'bg-rose-100 text-rose-800', 'error' => 'bg-rose-100 text-rose-700',
        'warning' => 'bg-amber-100 text-amber-800', 'notice' => 'bg-sky-100 text-sky-800',
        'info' => 'bg-emerald-100 text-emerald-800', 'debug' => 'bg-brand-sand/60 text-brand-moss',
    ];
@endphp
<div class="space-y-6">
    <x-hero-card
        :eyebrow="__('Logs')"
        :title="__('App logs')"
        :description="__('Application log records received from this site via the dply Logs drain.')"
        icon="document-text"
    />

    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-end gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-8">
            <div class="flex items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search messages') }}" class="dply-input text-xs" />
            <select wire:model.live="levelFilter" class="dply-input text-xs">
                @foreach ($levels as $lvl)
                    <option value="{{ $lvl }}">{{ $lvl === '' ? __('All levels') : $lvl }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="refresh" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refresh" /> {{ __('Refresh') }}
            </button>
        </div>
    </div>

    @if ($records->isEmpty())
        <p class="px-6 py-10 text-center text-xs italic text-brand-mist sm:px-8">
            {{ __('No app logs received yet. Add a dply Logs channel, deploy, and once the drain receiver is running your logs appear here.') }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/5">
            @foreach ($records as $rec)
                <li wire:key="applog-{{ $rec->id }}" class="flex items-start gap-3 px-6 py-2.5 sm:px-8">
                    <span class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase {{ $levelColors[$rec->level] ?? 'bg-brand-sand/60 text-brand-moss' }}">{{ $rec->level ?? 'log' }}</span>
                    <span class="shrink-0 font-mono text-[10px] text-brand-mist">{{ optional($rec->logged_at ?? $rec->created_at)->format('H:i:s') }}</span>
                    <span class="min-w-0 flex-1 whitespace-pre-wrap break-words font-mono text-xs text-brand-ink">{{ $rec->message }}</span>
                </li>
            @endforeach
        </ul>
        @if ($records->count() >= $limit && $limit < 1000)
            <div class="border-t border-brand-ink/5 px-6 py-3 text-center sm:px-8">
                <button type="button" wire:click="loadMore" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Load more') }}</button>
            </div>
        @endif
    @endif
    </section>
</div>
