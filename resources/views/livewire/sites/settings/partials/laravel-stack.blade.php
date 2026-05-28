@if (! $site->isLaravelFrameworkDetected())
    <section class="space-y-6 rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Laravel') }}</h2>
        <p class="text-sm text-brand-moss">{{ __('This section appears when your site is detected as a Laravel application from repository inspection.') }}</p>
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
