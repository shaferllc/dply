@props(['class' => ''])

@if (session('status'))
    <div {{ $attributes->merge(['class' => 'rounded-xl border border-brand-sage/30 bg-brand-forest/[0.06] px-4 py-3 text-sm text-brand-forest '.$class]) }} role="status">
        {{ session('status') }}
    </div>
@endif
