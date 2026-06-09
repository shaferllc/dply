<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('CDN / Edge'),
        'currentIcon' => 'cloud',
        'contextualDocSlug' => app(\App\Support\Docs\ContextualDocResolver::class)->resolveForSiteSection($site, 'cdn'),
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :eyebrow="__('CDN / Edge')"
                :title="__('Edge cache & proxy')"
                :description="__('Put a CDN edge network in front of this site\'s origin — preview what is shipping next.')"
                :show-documentation="false"
                flush
                compact
            />

            <x-cdn-preview-panel :site="$site" />
        </main>
    </div>
</div>
