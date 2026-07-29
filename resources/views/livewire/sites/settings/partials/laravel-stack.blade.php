{{-- Nested inside Settings Laravel merged card — strips + sand CLI footer. --}}
<div class="min-w-0">
    @if (! $site->isLaravelFrameworkDetected())
        <div class="px-5 py-5 sm:px-6">
            <p class="text-sm leading-relaxed text-brand-moss">
                {{ __('This section appears when your site is detected as a Laravel application from repository inspection.') }}
            </p>
        </div>
    @else
        @include('livewire.sites.settings.partials.laravel.workspace')
    @endif

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
        <x-cli-snippet :commands="[
            ['label' => __('Migration status'), 'command' => 'dply:artisan '.$site->slug.' -- migrate:status'],
            ['label' => __('Run migrations'), 'command' => 'dply:artisan '.$site->slug.' -- migrate --force'],
            ['label' => __('Roll back one batch'), 'command' => 'dply:laravel:migrate:rollback '.$site->slug.' --step=1 --snapshot-first'],
            ['label' => __('Run any artisan command'), 'command' => 'dply:artisan '.$site->slug.' -- about'],
        ]" />
    </div>
</div>
