{{-- Server logo: custom image shown beside this server across the dashboard,
     breadcrumbs, and list views; falls back to the gradient avatar. Lives on the
     Configuration workspace (host-level settings) — backed by the
     ManagesServerLogo trait, so any component including this must use it. --}}
@php $canEditServerLogo = auth()->user()?->can('update', $server); @endphp
<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge tone="gold">
            <x-heroicon-o-photo class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logo') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Server logo') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Shown beside this server across the dashboard, breadcrumbs, and lists. PNG, JPG, WEBP, GIF or ICO up to 1 MB. Leave empty to use the generated avatar.') }}
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
        @if (session('logo_status'))
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">{{ session('logo_status') }}</div>
        @endif

        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <div class="shrink-0">
                <x-entity-avatar :seed="$server->name ?: $server->id" :image="$server->logoUrl()" class="h-16 w-16 text-lg" />
            </div>

            @if ($canEditServerLogo)
                <div class="min-w-0 flex-1 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                            <span wire:loading.remove wire:target="server_logo_upload">{{ __('Upload image') }}</span>
                            <span wire:loading wire:target="server_logo_upload">{{ __('Uploading…') }}</span>
                            <input type="file" wire:model="server_logo_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                        </label>

                        @if ($server->hasLogo())
                            <button
                                type="button"
                                wire:click="removeServerLogo"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                                {{ __('Remove') }}
                            </button>
                        @endif
                    </div>

                    @error('server_logo_upload')
                        <p class="text-xs font-medium text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('You don\'t have permission to change this server\'s logo.') }}</p>
            @endif
        </div>
    </div>
</section>
