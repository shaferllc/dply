<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Caching'),
        'currentIcon' => 'bolt',
        'contextualDocSlug' => app(\App\Support\Docs\ContextualDocResolver::class)->resolveForSiteSection($site, 'caching'),
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :eyebrow="__('Caching')"
                :title="__('Site cache layers')"
                :description="__('Per-site HTTP cache directives, opcode caches, and Varnish toggles — preview what is shipping next.')"
                :show-documentation="false"
                flush
                compact
            />

            <x-caching-preview-panel :site="$site" />
        </main>
    </div>
</div>
