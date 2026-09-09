<section class="border-t border-brand-ink/10 bg-brand-sand/25 px-4 py-16 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-2xl text-center">
        <h2 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('See it on your own servers') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-brand-moss sm:text-base">{{ __('Connect a provider, create a server, and ship a real deploy — on infrastructure you already control.') }}</p>
        <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
            @auth
                <a href="{{ route('dashboard') }}" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-ink px-6 py-3 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:w-auto">{{ __('Go to dashboard') }}</a>
                <a href="{{ route('docs.index') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-6 py-3 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40 sm:w-auto">{{ __('Open docs') }}</a>
            @else
                <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center rounded-xl bg-brand-ink px-6 py-3 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest sm:w-auto">{{ __('Start free trial') }}</a>
                <a href="{{ route('pricing') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-6 py-3 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40 sm:w-auto">{{ __('View pricing') }}</a>
            @endauth
        </div>
    </div>
</section>
