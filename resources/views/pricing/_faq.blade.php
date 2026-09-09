<section class="border-t border-brand-ink/10 bg-white/60 px-4 py-16 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-3xl">
        <h2 class="text-center text-2xl font-bold tracking-tight text-brand-ink">{{ __('Frequently asked') }}</h2>
        <div class="mt-8 space-y-2">
            @foreach ($faqs as $faq)
                <details class="group rounded-xl border border-brand-ink/10 bg-white/80 px-5 py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                        <span class="font-semibold text-brand-ink">{{ __($faq['q']) }}</span>
                        <x-heroicon-m-chevron-down class="h-5 w-5 shrink-0 text-brand-moss transition-transform group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-brand-moss">{{ __($faq['a']) }}</p>
                </details>
            @endforeach
        </div>
        <p class="mt-10 text-center text-sm text-brand-moss">
            {{ __('Still curious?') }}
            <a href="mailto:hello@dply.io" class="font-semibold text-brand-ink underline underline-offset-2 hover:text-brand-sage">{{ __('Email us') }}</a>.
        </p>
    </div>
</section>
