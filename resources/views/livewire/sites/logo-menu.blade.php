{{--
    Site-logo avatar + edit menu (LogoMenu component). The avatar doubles as
    the control: an always-visible corner pencil, click opens a small menu with
    Upload / Pull favicon / Remove. Rendered in the workspace sidebar and the
    General tab's Overview header.
--}}
@php
    // Seed for the gradient + initials fallback (mirrors list rows) so the
    // preview matches what renders when no custom logo is set.
    $logoSeed = (string) (optional($site->primaryDomain())->hostname ?: $site->name ?: $site->id);
    $canEditLogo = auth()->user()?->can('update', $site);
    // Re-open the menu after an action round-trip so the flash feedback is seen.
    $logoMenuOpen = (bool) (session('logo_status') || session('logo_error'));
@endphp

<div
    x-data="{ open: @js($logoMenuOpen) }"
    @if ($canEditLogo) x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" @endif
    class="relative shrink-0"
>
    @if ($canEditLogo)
        {{-- inline-flex so the button hugs the avatar exactly (a block button
             picks up inline-image baseline gaps, stranding the corner badge). --}}
        <button
            type="button"
            x-on:click="open = ! open"
            class="group relative inline-flex rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/50"
            title="{{ __('Change site logo') }}"
            aria-haspopup="true"
            x-bind:aria-expanded="open"
        >
            <x-entity-avatar :seed="$logoSeed" :image="$site->logoUrl()" :class="$avatarClass" />
            {{-- Always-visible pencil pinned to the avatar's lower-right corner
                 so the logo reads as editable at a glance. --}}
            <span class="absolute bottom-0 right-0 flex h-4 w-4 translate-x-1/4 translate-y-1/4 items-center justify-center rounded-full bg-brand-ink/70 text-white shadow-sm ring-1 ring-white/60 transition group-hover:bg-brand-ink">
                <x-heroicon-m-pencil class="h-2.5 w-2.5" aria-hidden="true" />
            </span>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.origin.top.left
            class="absolute left-0 top-full z-20 mt-2 w-64 rounded-xl border border-brand-ink/10 bg-white p-3 shadow-lg"
        >
            @if (session('logo_status'))
                <div class="mb-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">{{ session('logo_status') }}</div>
            @endif
            @if (session('logo_error'))
                <div class="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">{{ session('logo_error') }}</div>
            @endif

            <div class="flex flex-col gap-1">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-up-tray class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                    <span wire:loading.remove wire:target="site_logo_upload">{{ __('Upload image') }}</span>
                    <span wire:loading wire:target="site_logo_upload">{{ __('Uploading…') }}</span>
                    <input type="file" wire:model="site_logo_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                </label>

                <button
                    type="button"
                    wire:click="pullSiteLogoFromFavicon"
                    wire:loading.attr="disabled"
                    wire:target="pullSiteLogoFromFavicon"
                    class="inline-flex items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-progress disabled:opacity-60"
                >
                    <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" wire:loading.remove wire:target="pullSiteLogoFromFavicon" aria-hidden="true" />
                    <span wire:loading wire:target="pullSiteLogoFromFavicon" class="inline-flex h-4 w-4 shrink-0 items-center justify-center"><x-spinner size="sm" /></span>
                    <span wire:loading.remove wire:target="pullSiteLogoFromFavicon">{{ __('Pull site favicon') }}</span>
                    <span wire:loading wire:target="pullSiteLogoFromFavicon">{{ __('Fetching…') }}</span>
                </button>

                @if ($site->hasLogo())
                    <button
                        type="button"
                        wire:click="removeSiteLogo"
                        class="inline-flex items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50"
                    >
                        <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Remove') }}
                    </button>
                @endif
            </div>

            @error('site_logo_upload')
                <p class="mt-2 text-xs font-medium text-red-700">{{ $message }}</p>
            @enderror

            <p class="mt-2 border-t border-brand-ink/10 pt-2 text-xs leading-relaxed text-brand-mist">
                {{ __('PNG, JPG, WEBP, GIF or ICO up to 1 MB. Favicon pull works for publicly reachable sites.') }}
            </p>
        </div>
    @else
        <x-entity-avatar :seed="$logoSeed" :image="$site->logoUrl()" :class="$avatarClass" />
    @endif
</div>
