@php
    $packages = $site->detectedLaravelPackageKeys();
@endphp

<div class="space-y-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 p-4 sm:p-5">
    <div>
        <h5 class="text-sm font-semibold text-brand-ink">{{ __('Laravel ecosystem (from composer.json)') }}</h5>
        <p class="mt-1 text-xs text-brand-moss">{{ __('Dply does not install these on the server automatically. Use the hints below with Supervisor on your server and your web server config.') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @include('livewire.sites.settings.partials.laravel.horizon-pulse-fields', ['site' => $site, 'server' => $server])
        @include('livewire.sites.settings.partials.laravel.reverb-fields', ['site' => $site, 'server' => $server])
    </div>
</div>
