@props([
    'promptUser',
    'promptHost',
    'serverReady' => true,
    'error' => null,
    'showRetry' => false,
    'placeholder' => null,
    'compact' => false,
])

@php
    $placeholder ??= $serverReady
        ? __('Type a command and press Enter')
        : __('Server unavailable — select another');
@endphp

<form {{ $attributes->merge(['class' => 'relative']) }} wire:submit.prevent="run">
    @if ($error)
        <div @class([
            'rounded-lg border border-rose-300/30 bg-rose-500/10 px-3 py-2',
            'mb-2' => ! $compact,
            'mb-2.5' => $compact,
        ])>
            <p class="text-[11px] leading-relaxed text-rose-200">{{ $error }}</p>
            @if ($showRetry)
                <button
                    type="button"
                    wire:click="verifyActiveServer"
                    class="mt-1 text-[10px] font-semibold text-rose-200 underline-offset-2 hover:underline"
                >
                    {{ __('Retry connection') }}
                </button>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-2.5">
        <div class="flex min-w-0 flex-1 items-center gap-2 font-mono text-[12px]">
            <span class="shrink-0 select-none text-emerald-400/90">$</span>
            <span class="hidden shrink-0 select-none text-slate-500 sm:inline">{{ $promptUser.'@'.$promptHost }}</span>
            <input
                type="text"
                wire:model="command"
                x-ref="prompt"
                autocomplete="off"
                autocorrect="off"
                spellcheck="false"
                placeholder="{{ $placeholder }}"
                class="min-w-0 flex-1 border-0 bg-transparent p-0 font-mono text-[12px] text-slate-100 caret-emerald-300 placeholder:text-slate-600 focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-40"
                wire:loading.attr="disabled"
                wire:target="run,runQuickAction,selectServer"
                @disabled(! $serverReady)
            />
        </div>
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="run,runQuickAction,selectServer"
            @disabled(! $serverReady)
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-emerald-400 px-3 py-1.5 text-[11px] font-semibold text-[#0b1020] shadow-sm transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-40"
        >
            <span wire:loading.remove wire:target="run,runQuickAction">{{ __('Run') }}</span>
            <span wire:loading wire:target="run,runQuickAction" class="inline-flex items-center gap-1.5">
                <x-spinner variant="ink" size="sm" />
                {{ __('…') }}
            </span>
        </button>
    </div>

    @error('command')
        <p class="mt-1.5 text-[11px] text-rose-300">{{ $message }}</p>
    @enderror
</form>
