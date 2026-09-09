{{-- Design 3 — Sticky spine: pitch pinned left, features scroll on the right. --}}
<x-marketing-layout title="Features" description="Provision servers, deploy from git, and run TLS, databases, cron, firewall, monitoring, and backups from one console." active="features">
    <section class="px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div class="mx-auto grid max-w-6xl gap-12 lg:grid-cols-12 lg:gap-16">
            {{-- Spine --}}
            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-24">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Features') }}</p>
                    <h1 class="mt-3 text-4xl font-bold leading-tight tracking-tight text-brand-ink sm:text-5xl">
                        {{ __('One console for the whole box.') }}
                    </h1>
                    <p class="mt-5 text-base leading-relaxed text-brand-moss sm:text-lg">
                        {{ __('Provision or bring your own server, ship from git, and keep TLS, databases, workers, firewall, monitoring, and backups in the same place as the team that runs them.') }}
                    </p>

                    <nav class="mt-8 hidden border-t border-brand-ink/10 pt-6 lg:block" aria-label="{{ __('On this page') }}">
                        <ol class="space-y-1.5">
                            @foreach ($features as $i => $f)
                                <li>
                                    <a href="#f{{ $i }}" class="group flex items-baseline gap-3 rounded-lg px-2 py-1 text-sm text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink">
                                        <span class="text-xs font-semibold tabular-nums text-brand-mist group-hover:text-brand-gold">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                        {{ __($f['title']) }}
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    </nav>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ auth()->check() ? route('dashboard') : route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            {{ auth()->check() ? __('Open dashboard') : __('Start free trial') }}
                        </a>
                        <a href="{{ route('pricing') }}" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink transition-colors hover:bg-brand-sand/40">{{ __('View pricing') }}</a>
                    </div>
                </div>
            </div>

            {{-- Feature column --}}
            <div class="lg:col-span-7">
                <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                    @foreach ($features as $i => $f)
                        <article id="f{{ $i }}" class="scroll-mt-24 p-6 transition-colors hover:bg-brand-sand/15 sm:p-7">
                            <div class="flex items-start gap-4">
                                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/50 text-brand-forest">
                                    <x-dynamic-component :component="'heroicon-o-' . $f['icon']" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-lg font-bold tracking-tight text-brand-ink">{{ __($f['title']) }}</h2>
                                    <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ __($f['blurb']) }}</p>
                                    <ul class="mt-3 flex flex-wrap gap-1.5">
                                        @foreach ($f['tags'] as $tag)
                                            <li class="rounded-full border border-brand-ink/10 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">{{ __($tag) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @include('features._cta')
</x-marketing-layout>
