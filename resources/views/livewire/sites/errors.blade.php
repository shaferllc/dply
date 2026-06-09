<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Errors'),
        'currentIcon' => 'exclamation-triangle',
        'contextualDocSlug' => 'vm-site-errors',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            @if (workspace_surface_coming_soon('site_errors'))
                <x-workspace-coming-soon
                    :server="$site->server"
                    icon="heroicon-o-exclamation-triangle"
                    :title="__('Errors')"
                    :description="__('A dedicated stream of everything that failed for this site — deploys, SSL, connectivity, cron — newest first, grouped by cause, with retry where it is supported.')"
                    :eyebrow="__('Error stream preview')"
                    :lines="[
                        ['tone' => 'cmd', 'text' => '~ $ dply errors --site'],
                        ['tone' => 'muted', 'text' => '12:04  deploy   composer install exited 1'],
                        ['tone' => 'muted', 'text' => '11:30  ssl      challenge failed (DNS)'],
                        ['tone' => 'ok', 'text' => '2 open · retry available'],
                    ]"
                    :features="[
                        ['icon' => 'inbox-stack', 'title' => __('One failure stream'), 'body' => __('Deploys, SSL, connectivity, and cron faults in a single feed — like logs, but only errors.')],
                        ['icon' => 'square-3-stack-3d', 'title' => __('Grouped by cause'), 'body' => __('Repeats collapse into one entry with a count so noise becomes signal.')],
                        ['icon' => 'arrow-path', 'title' => __('Retry in place'), 'body' => __('Re-run the original operation where supported, or jump to its source.')],
                        ['icon' => 'users', 'title' => __('Shared dismiss'), 'body' => __('Clear what your team has handled so the queue reflects reality.')],
                    ]"
                />
            @else
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

                <x-server-workspace-tablist :aria-label="__('Errors workspace sections')" scroll class="sm:min-w-0 sm:flex-1">
                    <x-server-workspace-tab
                        id="errors-tab-stream"
                        icon="heroicon-o-exclamation-triangle"
                        :active="$errorsTab === 'stream'"
                        wire:click="setErrorsWorkspaceTab('stream')"
                    >
                        {{ __('Stream') }}
                    </x-server-workspace-tab>
                    <x-server-workspace-tab
                        id="errors-tab-notifications"
                        icon="heroicon-o-bell"
                        :active="$errorsTab === 'notifications'"
                        wire:click="setErrorsWorkspaceTab('notifications')"
                    >
                        {{ __('Notifications') }}
                    </x-server-workspace-tab>
                </x-server-workspace-tablist>

                @if ($errorsTab === 'stream')
                    @include('livewire.partials.error-stream')
                    <x-cli-snippet class="mt-6" :command="'dply sites:errors '.$site->slug" />
                @endif

                @if ($errorsTab === 'notifications')
                    @include('livewire.sites.partials.errors.notifications-tab')
                @endif
            @endif
        </main>
    </div>

    @include('livewire.partials.confirm-action-modal')

    @include('livewire.partials.create-notification-channel-modal')
</div>
