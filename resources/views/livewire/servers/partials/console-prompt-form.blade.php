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
    $prompt = $promptUser.'@'.$promptHost;
    $placeholder ??= $serverReady
        ? __('Type a command and press Enter')
        : __('Server unavailable — select another');
@endphp

<form {{ $attributes->merge(['class' => 'relative']) }} wire:submit.prevent="run">
    @if ($error)
        <div @class([
            'rounded-lg border border-rose-200 bg-rose-50/90 px-3 py-2',
            'mb-2' => ! $compact,
            'mb-2.5' => $compact,
        ])>
            <p class="text-[11px] leading-relaxed text-rose-800">{{ $error }}</p>
            @if ($showRetry)
                <button
                    type="button"
                    wire:click="verifyActiveServer"
                    class="mt-1 text-[10px] font-semibold text-rose-700 underline-offset-2 hover:underline"
                >
                    {{ __('Retry connection') }}
                </button>
            @endif
        </div>
    @endif

    <div class="flex items-center gap-2 font-mono text-[12px] sm:text-[12.5px]">
        <span class="hidden shrink-0 text-brand-sage sm:inline">{{ $prompt }}</span>
        <span class="shrink-0 text-slate-500">:~$</span>
        <input
            type="text"
            wire:model="command"
            x-ref="prompt"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            placeholder="{{ $placeholder }}"
            class="min-w-0 flex-1 rounded-md border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-100 placeholder-slate-500 caret-brand-sage focus:border-brand-sage/40 focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-sage/20 disabled:cursor-not-allowed disabled:opacity-50"
            wire:loading.attr="disabled"
            wire:target="run,runQuickAction,selectServer"
            @disabled(! $serverReady)
        />
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="run,runQuickAction,selectServer"
            @disabled(! $serverReady)
            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-40"
        >
            <span wire:loading.remove wire:target="run,runQuickAction">{{ __('Run') }}</span>
            <span wire:loading wire:target="run,runQuickAction" class="inline-flex items-center gap-1.5">
                <x-spinner variant="cream" size="sm" />
                {{ __('Running') }}
            </span>
        </button>
    </div>

    @error('command')
        <p class="mt-1.5 text-[11px] text-rose-300">{{ $message }}</p>
    @enderror
</form>
