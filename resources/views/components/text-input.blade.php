@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50']) }}>
