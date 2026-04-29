<div class="min-h-screen bg-brand-cream text-brand-ink">
    <div class="fixed inset-0 -z-20 bg-brand-cream"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(ellipse_100%_80%_at_50%_-30%,rgba(205,169,66,0.08),transparent_55%)]"></div>

    <x-site-header :show-guest-signup="false" />

    <main class="px-4 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
        <section class="mx-auto max-w-7xl">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)] lg:items-center">
                <div class="max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-brand-sage/20 bg-white/75 px-4 py-1.5 text-xs font-semibold uppercase tracking-[0.2em] text-brand-forest">
                        <span class="h-2 w-2 rounded-full bg-brand-gold" aria-hidden="true"></span>
                        {{ $eyebrow }}
                    </p>
                    <h1 class="mt-8 text-4xl font-bold tracking-tight text-brand-ink sm:text-5xl lg:text-6xl lg:leading-[1.05]">
                        {{ $headline }}
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-brand-moss sm:text-xl">
                        {{ $subheadline }}
                    </p>

                    <dl class="mt-10 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/75 p-4 shadow-sm">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Access') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-brand-ink">{{ __('Early invite list') }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/75 p-4 shadow-sm">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('For teams') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-brand-ink">{{ __('Ops, platform, and delivery') }}</dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white/75 p-4 shadow-sm">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Existing users') }}</dt>
                            <dd class="mt-2 text-sm font-medium text-brand-ink">{{ __('Use the login path below') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-3xl border border-brand-ink/10 bg-white/90 p-6 shadow-xl shadow-brand-forest/10 ring-1 ring-brand-ink/5 sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Get launch updates') }}</p>
                            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Request early access') }}</h2>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-brand-sand/50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-brand-rust">
                            {{ __('Email list') }}
                        </span>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-brand-moss">
                        {{ __('Leave your email and we will reach out when the live rollout is ready. Existing customers can continue straight to login.') }}
                    </p>

                    @if ($submitted)
                        <div class="mt-6 rounded-2xl border border-brand-sage/20 bg-brand-sage/10 px-4 py-4 text-sm leading-6 text-brand-forest">
                            {{ $successMessage }}
                        </div>
                    @endif

                    <form wire:submit="submit" class="mt-6 space-y-4">
                        <div>
                            <x-input-label for="coming_soon_email" :value="__('Work email')" />
                            <x-text-input
                                id="coming_soon_email"
                                wire:model.live.debounce.300ms="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                class="w-full"
                                placeholder="you@company.com"
                            />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <x-primary-button class="w-full" wire:loading.attr="disabled" wire:target="submit">
                            <span wire:loading.remove wire:target="submit">{{ __('Join the list') }}</span>
                            <span wire:loading wire:target="submit">{{ __('Saving...') }}</span>
                        </x-primary-button>
                    </form>

                    <div class="mt-6 flex flex-col gap-3 border-t border-brand-ink/10 pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-sm text-brand-moss">
                            {{ __('Already have access?') }}
                        </div>
                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center justify-center rounded-xl border border-brand-ink/10 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/30 hover:bg-brand-sand/20"
                        >
                            {{ __('Log in') }}
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
