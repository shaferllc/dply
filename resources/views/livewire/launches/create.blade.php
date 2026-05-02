<div>
    <div class="border-b border-brand-ink/10 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :title="__('Launch setup')"
                :description="__('Pick how you want to deploy. Right now you can start with bring-your-own-server (SSH-managed VMs); other deployment models are on the way.')"
                doc-route="docs.index"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Back') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="min-h-[50vh] bg-brand-cream py-10">
        <div class="dply-page-shell">
            <section aria-labelledby="launch-options-heading">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Choose a path') }}</p>
                        <h2 id="launch-options-heading" class="mt-2 text-2xl font-semibold text-brand-ink">{{ __('Start from the deployment model, not the provider card') }}</h2>
                    </div>
                    <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Provider setup guide') }}</a>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($launchOptions as $option)
                        @php
                            $iconComponent = 'heroicon-o-'.$option['icon'];
                        @endphp

                        @if ($option['enabled'] ?? false)
                            <a
                                href="{{ $option['href'] }}"
                                wire:navigate
                                class="group relative flex flex-col rounded-2xl border-2 border-brand-sage/35 bg-white p-6 shadow-sm ring-1 ring-brand-ink/[0.06] transition hover:-translate-y-0.5 hover:border-brand-sage/55 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40"
                            >
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                                    <x-dynamic-component :component="$iconComponent" class="h-7 w-7 shrink-0" aria-hidden="true" />
                                </span>
                                <h3 class="mt-4 text-lg font-semibold text-brand-ink">{{ $option['title'] }}</h3>
                                <p class="mt-3 flex-1 text-sm leading-6 text-brand-moss">{{ $option['description'] }}</p>
                                <p class="mt-5 text-sm font-semibold text-brand-sage group-hover:text-brand-ink">{{ __('Open path') }} →</p>
                            </a>
                        @else
                            <div
                                class="relative flex flex-col rounded-2xl border border-brand-ink/10 bg-white/70 p-6 opacity-[0.88] shadow-sm ring-1 ring-brand-ink/[0.04]"
                                aria-disabled="true"
                                role="group"
                                aria-labelledby="launch-soon-{{ $option['id'] }}"
                            >
                                <span class="absolute end-4 top-4 inline-flex rounded-full bg-brand-ink/[0.06] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                    {{ __('Coming soon') }}
                                </span>
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-ink/[0.04] text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-dynamic-component :component="$iconComponent" class="h-7 w-7 shrink-0 opacity-80" aria-hidden="true" />
                                </span>
                                <h3 id="launch-soon-{{ $option['id'] }}" class="mt-4 text-lg font-semibold text-brand-ink">{{ $option['title'] }}</h3>
                                <p class="mt-3 flex-1 text-sm leading-6 text-brand-moss">{{ $option['description'] }}</p>
                                <p class="mt-5 text-sm font-medium text-brand-mist">{{ __('Not available yet') }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</div>
