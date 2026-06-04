<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Errors'),
        'currentIcon' => 'exclamation-triangle',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Errors')"
                :description="__('Every failure for this site — deploys, SSL, connectivity, and more. Newest first. Dismiss what you’ve handled; retry where supported.')"
                :show-documentation="false"
                flush
                compact
            />

            <x-explainer tone="info">
                <p>{{ __('A dedicated stream of this site’s failed operations — like the logs, but only errors. Dismiss is shared with your team; retry re-runs the original operation where supported, otherwise open the error to act at its source.') }}</p>
            </x-explainer>

            @include('livewire.partials.error-stream')
        </main>
    </div>
</div>
