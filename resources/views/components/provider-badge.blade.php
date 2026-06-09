@props([
    'provider',
    'label',
])

<div {{ $attributes->merge(['class' => 'inline-flex w-fit max-w-full items-center gap-2.5 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3.5 py-2 text-sm font-semibold text-brand-ink']) }}>
    <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/80 text-[#0080FF] ring-1 ring-brand-ink/10">
        <x-credentials-provider-icon :provider="$provider" />
    </span>
    <span class="min-w-0 truncate sm:whitespace-nowrap">{{ $label }}</span>
</div>
