<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 focus:ring-offset-white disabled:cursor-not-allowed disabled:opacity-50']) }}>
    {{ $slot }}
</button>
