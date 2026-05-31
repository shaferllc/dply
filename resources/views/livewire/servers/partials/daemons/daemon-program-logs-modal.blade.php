<x-modal
    name="daemon-program-logs-modal"
    :show="false"
    maxWidth="4xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,920px)] flex-col"
    focusable
>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Program logs') }}</p>
                <h2 class="mt-1 font-mono text-lg font-semibold text-brand-ink">
                    {{ $log_tail_slug !== '' ? $log_tail_slug : __('Supervisor program') }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ __('Last 200 lines from this program’s Supervisor log file on the server.') }}
                </p>
            </div>
            <button
                type="button"
                wire:click="closeProgramLogsModal"
                class="shrink-0 rounded-lg p-2 text-brand-moss transition hover:bg-brand-sand/50 hover:text-brand-ink"
                aria-label="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>

        <div
            class="flex min-h-0 flex-1 flex-col gap-4 overflow-hidden p-6"
            @if ($log_follow_enabled && $log_tail_program_id)
                wire:poll.3s="refreshLogTailFollow"
            @endif
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div>
                    <x-input-label for="log_which_modal" value="{{ __('Stream') }}" />
                    <select
                        id="log_which_modal"
                        wire:model.live="log_which"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm sm:w-40"
                    >
                        <option value="stdout">stdout</option>
                        <option value="stderr">stderr</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm text-brand-ink sm:pb-2">
                    <input
                        type="checkbox"
                        wire:model.live="log_follow_enabled"
                        class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage"
                    />
                    {{ __('Follow (poll every 3s)') }}
                </label>
                <button
                    type="button"
                    wire:click="tailProgramLog"
                    wire:loading.attr="disabled"
                    wire:target="tailProgramLog,openProgramLogs,refreshLogTailFollow"
                    @disabled($supervisor_installed !== true)
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50 sm:ml-auto"
                >
                    <span wire:loading.remove wire:target="tailProgramLog,openProgramLogs,refreshLogTailFollow" class="inline-flex items-center gap-1.5">
                        <x-heroicon-m-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Refresh') }}
                    </span>
                    <span wire:loading wire:target="tailProgramLog,openProgramLogs,refreshLogTailFollow" class="inline-flex items-center gap-2">
                        <x-spinner variant="forest" />
                        {{ __('Loading…') }}
                    </span>
                </button>
            </div>

            <pre class="min-h-[12rem] flex-1 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $log_tail_body !== '' ? $log_tail_body : __('Loading log output…') }}</pre>
        </div>

        <div class="flex shrink-0 justify-end border-t border-brand-ink/10 px-6 py-4">
            <button
                type="button"
                wire:click="closeProgramLogsModal"
                class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Close') }}
            </button>
        </div>
    </div>
</x-modal>
