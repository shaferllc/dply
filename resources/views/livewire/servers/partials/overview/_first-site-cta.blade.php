{{-- First site / container app CTA. Shown on hosts that run site code
     (container hosts + VM app/worker hosts) — not dedicated cache/db boxes. --}}
@if ($siteCount === 0 && ! $containerLaunch && ! $isDedicatedServiceRoleHost)
    <section data-testid="{{ $isContainerHost ? 'add-first-container-cta' : 'add-first-site-cta' }}" class="dply-card overflow-hidden">
        <div class="px-6 pt-5 pb-4 sm:px-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                    <x-icon-badge>
                        <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        @if ($isContainerHost)
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add your first container app') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point dply at a Git repo and we will inspect the Dockerfile, build the image, and deploy onto this host. You can add more apps any time.') }}</p>
                        @else
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add your first site') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Connect a Git repo, configure the web root, and deploy. You can add more sites any time.') }}</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('sites.create', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-xl bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-sky-700">
                    <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ $isContainerHost ? __('Add container app') : __('Add site') }}
                </a>
            </div>
        </div>
    </section>
@endif
