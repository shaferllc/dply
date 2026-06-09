<div>
@php
    $statusBadge = function (string $status): array {
        return match ($status) {
            'finished' => ['bg-emerald-50', 'text-emerald-900', 'ring-emerald-200'],
            'failed' => ['bg-red-50', 'text-red-900', 'ring-red-200'],
            'running' => ['bg-sky-50', 'text-sky-900', 'ring-sky-200'],
            'queued' => ['bg-amber-50', 'text-amber-900', 'ring-amber-200'],
            default => ['bg-brand-sand/60', 'text-brand-moss', 'ring-brand-ink/10'],
        };
    };
@endphp
<section class="dply-card overflow-hidden" wire:poll.5s>
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-clipboard-document-list class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Activity') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent action logs') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Installs and per-service actions, newest first. Click a row to view the SSH output.') }}</p>
        </div>
        <p class="ml-auto shrink-0 text-xs text-brand-mist">{{ trans_choice(':count entry|:count entries', $rows->count(), ['count' => $rows->count()]) }}</p>
    </div>

    @if ($rows->isEmpty())
        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No actions have run on this server yet.') }}</p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($rows as $row)
                @php
                    [$bg, $text, $ring] = $statusBadge((string) $row->status);
                    $hasOutput = is_string($row->output) && trim($row->output) !== '';
                    $hasError = is_string($row->error_message) && trim($row->error_message) !== '';
                    $clickable = $hasOutput || $hasError;
                @endphp
                <li class="px-6 py-3 sm:px-8">
                    <button
                        type="button"
                        @if ($clickable) wire:click="viewLog('{{ $row->id }}')" @endif
                        @class([
                            'flex w-full flex-wrap items-center justify-between gap-3 text-left',
                            'cursor-pointer hover:opacity-80' => $clickable,
                            'cursor-default' => ! $clickable,
                        ])
                        @disabled(! $clickable)
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 {{ $bg }} {{ $text }} {{ $ring }}">{{ strtoupper($row->status) }}</span>
                                <span class="text-sm font-medium text-brand-ink truncate">{{ $row->label ?: $row->task_name }}</span>
                            </div>
                            <p class="mt-1 text-xs text-brand-mist font-mono break-all">{{ $row->task_name }}</p>
                            @if ($hasError)
                                <p class="mt-1 text-xs text-red-700 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ \Illuminate\Support\Str::limit($row->error_message, 240) }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                            <span class="text-xs text-brand-mist whitespace-nowrap">
                                @if ($row->finished_at)
                                    {{ $row->finished_at->diffForHumans() }}
                                @elseif ($row->started_at)
                                    {{ __('Started') }} {{ $row->started_at->diffForHumans() }}
                                @else
                                    {{ $row->created_at?->diffForHumans() }}
                                @endif
                            </span>
                            @if ($clickable)
                                <span class="text-[11px] font-medium text-brand-forest">{{ __('View output') }} →</span>
                            @endif
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if ($showOpenModal && $openLog)
    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="action-log-modal-title"
        x-data
        x-on:keydown.escape.window="$wire.closeLog()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeLog"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <div class="relative w-full max-w-3xl dply-modal-panel" wire:click.stop>
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                    <h2 id="action-log-modal-title" class="text-base font-semibold text-brand-ink">{{ $openLog->label ?: $openLog->task_name }}</h2>
                    <p class="mt-1 text-xs text-brand-moss font-mono break-all">{{ $openLog->task_name }}</p>
                </div>
                <div class="space-y-3 px-6 py-5 sm:px-7">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-xs text-brand-moss">
                        <div>
                            <dt class="font-medium uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-0.5 font-semibold text-brand-ink">{{ strtoupper($openLog->status) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium uppercase tracking-wide text-brand-mist">{{ __('Started') }}</dt>
                            <dd class="mt-0.5 font-semibold text-brand-ink">{{ $openLog->started_at?->format('Y-m-d H:i:s T') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium uppercase tracking-wide text-brand-mist">{{ __('Finished') }}</dt>
                            <dd class="mt-0.5 font-semibold text-brand-ink">{{ $openLog->finished_at?->format('Y-m-d H:i:s T') ?? '—' }}</dd>
                        </div>
                    </div>
                    @if ($openLog->error_message)
                        <div class="rounded-lg border border-red-200/80 bg-red-50/80 px-3 py-2 text-xs text-red-900 whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{{ $openLog->error_message }}</div>
                    @endif
                    @if (is_string($openLog->output) && trim($openLog->output) !== '')
                        <div
                            x-data="{
                                copied: false,
                                async copy() {
                                    try {
                                        await navigator.clipboard.writeText(this.$refs.raw.textContent);
                                        this.copied = true;
                                        setTimeout(() => { this.copied = false; }, 1500);
                                    } catch (e) { /* clipboard blocked */ }
                                },
                            }"
                            class="rounded-xl border border-brand-ink/15 bg-zinc-50 p-3 shadow-inner"
                        >
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-ink">{{ __('Output') }}</p>
                                <button type="button" @click="copy()" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <template x-if="copied">
                                        <span class="inline-flex items-center gap-1 text-emerald-700">
                                            <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('Copied') }}
                                        </span>
                                    </template>
                                    <template x-if="! copied">
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-o-clipboard class="h-3 w-3 shrink-0 text-brand-ink/70" aria-hidden="true" />
                                            {{ __('Copy') }}
                                        </span>
                                    </template>
                                </button>
                            </div>
                            <pre x-ref="raw" class="hidden">{{ $openLog->output }}</pre>
                            <pre class="font-mono text-[11px] leading-snug whitespace-pre-wrap break-words text-zinc-900 [overflow-wrap:anywhere] max-h-[60vh] overflow-y-auto">{{ $openLog->output }}</pre>
                        </div>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('No output was captured for this action.') }}</p>
                    @endif
                </div>
                <div class="flex justify-end border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                    <button type="button" wire:click="closeLog" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
</div>
