<select {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</select>
