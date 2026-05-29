@if (! $site->isLaravelFrameworkDetected())
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Laravel') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Laravel') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('This section appears when your site is detected as a Laravel application from repository inspection.') }}</p>
            </div>
        </div>
    </section>
@else
    @include('livewire.sites.settings.partials.laravel.workspace')
@endif

<x-cli-snippet :commands="[
    ['label' => __('Migration status'), 'command' => 'dply:artisan '.$site->slug.' -- migrate:status'],
    ['label' => __('Run migrations'), 'command' => 'dply:artisan '.$site->slug.' -- migrate --force'],
    ['label' => __('Roll back one batch'), 'command' => 'dply:laravel:migrate:rollback '.$site->slug.' --step=1 --snapshot-first'],
    ['label' => __('Run any artisan command'), 'command' => 'dply:artisan '.$site->slug.' -- about'],
]" />
