@props([
    'flag' => [],
    'mode' => 'org',
    'disabled' => false,
])

<label @class([
    'flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-brand-ink/10 bg-white px-3 py-2.5 text-sm shadow-sm transition hover:border-brand-ink/15 has-[:checked]:border-brand-sage/50 has-[:checked]:bg-brand-sage/5',
    'cursor-not-allowed opacity-50' => $disabled,
])>
    <span class="flex min-w-0 flex-col gap-0.5">
        <span class="flex items-center gap-2">
            @if ($flag['active'] ?? false)
                <span class="inline-flex h-2 w-2 shrink-0 rounded-full bg-brand-sage" aria-hidden="true"></span>
            @else
                <span class="inline-flex h-2 w-2 shrink-0 rounded-full bg-brand-ink/15" aria-hidden="true"></span>
            @endif
            <span class="font-semibold text-brand-ink">{{ $flag['label'] }}</span>
        </span>
        <code class="font-mono text-[11px] text-brand-mist">{{ $flag['key'] }}</code>
        <span class="text-[10px] text-brand-mist">{{ __('config default :state', ['state' => ($flag['configDefault'] ?? $flag['default'] ?? false) ? __('on') : __('off')]) }}</span>
    </span>
    {{ $slot }}
</label>
