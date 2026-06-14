@php
    // Seed for the gradient + initials fallback (mirrors the sidebar avatar) so
    // the preview matches what renders when no custom logo is set.
    $logoSeed = (string) (optional($site->primaryDomain())->hostname ?: $site->name ?: $site->id);
    $canEditLogo = auth()->user()?->can('update', $site);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge tone="gold">
            <x-heroicon-o-photo class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logo') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Site logo') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Shown beside this site across the dashboard. Upload an image, or pull the favicon from the live site. PNG, JPG, WEBP, GIF or ICO up to 1 MB.') }}
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        @if (session('logo_status'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">{{ session('logo_status') }}</div>
        @endif
        @if (session('logo_error'))
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">{{ session('logo_error') }}</div>
        @endif

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            {{-- Preview --}}
            <div class="shrink-0">
                <x-entity-avatar :seed="$logoSeed" :image="$site->logoUrl()" class="h-16 w-16 text-lg" />
            </div>

            @if ($canEditLogo)
                <div class="min-w-0 flex-1 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                            <span wire:loading.remove wire:target="site_logo_upload">{{ __('Upload image') }}</span>
                            <span wire:loading wire:target="site_logo_upload">{{ __('Uploading…') }}</span>
                            <input type="file" wire:model="site_logo_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                        </label>

                        <button
                            type="button"
                            wire:click="pullSiteLogoFromFavicon"
                            wire:loading.attr="disabled"
                            wire:target="pullSiteLogoFromFavicon"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-progress disabled:opacity-60"
                        >
                            <x-heroicon-o-globe-alt class="h-4 w-4" wire:loading.remove wire:target="pullSiteLogoFromFavicon" />
                            <span wire:loading wire:target="pullSiteLogoFromFavicon" class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>
                            <span wire:loading.remove wire:target="pullSiteLogoFromFavicon">{{ __('Pull site favicon') }}</span>
                            <span wire:loading wire:target="pullSiteLogoFromFavicon">{{ __('Fetching…') }}</span>
                        </button>

                        @if ($site->hasLogo())
                            <button
                                type="button"
                                wire:click="removeSiteLogo"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                                {{ __('Remove') }}
                            </button>
                        @endif
                    </div>

                    @error('site_logo_upload')
                        <p class="text-xs font-medium text-red-700">{{ $message }}</p>
                    @enderror

                    <p class="text-xs text-brand-mist">
                        {{ __('Favicon pull works for sites reachable on a public address; local/testing hosts are skipped for security.') }}
                    </p>
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('You don\'t have permission to change this site\'s logo.') }}</p>
            @endif
        </div>
    </div>
</section>
