<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Services'),
        'currentIcon' => 'cpu-chip',
        'contextualDocSlug' => $contextualDocSlug,
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-hero-card
                :eyebrow="__('Background')"
                :title="__('Services')"
                :description="__('Manage the systemd units for this site — the web upstream and worker or scheduler processes.')"
                icon="cpu-chip"
            />

            @if (! $supportsSystemd)
                @include('livewire.sites.partials.systemd._unsupported')
            @else
                @include('livewire.sites.partials.systemd._workspace-content')
            @endif
        </main>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
