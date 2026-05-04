<section aria-labelledby="site-create-mode-heading" class="rounded-3xl border-2 border-brand-sage/20 bg-white p-6 shadow-sm sm:p-7">
    <div class="flex items-start gap-4">
        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest">
            <x-heroicon-o-rocket-launch class="h-5 w-5" />
        </span>
        <div class="min-w-0 flex-1">
            <h2 id="site-create-mode-heading" class="text-lg font-semibold text-brand-ink">{{ __('How are you starting this site?') }}</h2>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Bring an existing repo, or scaffold a fresh app from a starter template.') }}</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <button
            type="button"
            wire:click="chooseImportMode"
            @class([
                'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all',
                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $form->mode === 'import',
                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->mode !== 'import',
            ])
        >
            <span @class([
                'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-colors',
                'bg-brand-sage text-white shadow-sm' => $form->mode === 'import',
                'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->mode !== 'import',
            ])>
                <x-heroicon-o-cloud-arrow-up class="h-5 w-5" />
            </span>
            <span class="mt-3 block text-base font-semibold text-brand-ink">{{ __('Import an existing repo') }}</span>
            <span class="mt-1 block text-sm leading-relaxed text-brand-moss">{{ __('Connect a Git repository and dply will deploy whatever is on the chosen branch.') }}</span>
            @if ($form->mode === 'import')
                <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                    <x-heroicon-m-check class="h-3 w-3" />
                    {{ __('Picked') }}
                </span>
            @endif
        </button>

        <button
            type="button"
            wire:click="chooseScaffoldMode"
            @class([
                'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all',
                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $form->mode === 'scaffold',
                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $form->mode !== 'scaffold',
            ])
        >
            <span @class([
                'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition-colors',
                'bg-brand-sage text-white shadow-sm' => $form->mode === 'scaffold',
                'bg-brand-sand/40 text-brand-forest group-hover:bg-brand-sage/15' => $form->mode !== 'scaffold',
            ])>
                <x-heroicon-o-sparkles class="h-5 w-5" />
            </span>
            <span class="mt-3 block text-base font-semibold text-brand-ink">{{ __('Scaffold a new app') }}</span>
            <span class="mt-1 block text-sm leading-relaxed text-brand-moss">{{ __('Spin up a fresh Laravel or WordPress install — no repo needed. Database, admin user, and SSL all wired automatically.') }}</span>
            @if ($form->mode === 'scaffold')
                <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                    <x-heroicon-m-check class="h-3 w-3" />
                    {{ __('Picked') }}
                </span>
            @endif
        </button>
    </div>
</section>
