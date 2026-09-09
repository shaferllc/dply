{{-- Standalone Errors page — merged chrome (no floating hero). --}}
<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Errors'),
        'currentIcon' => 'exclamation-triangle',
        'contextualDocSlug' => 'vm-site-errors',
    ])

    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
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
                <section class="dply-card min-w-0 overflow-hidden p-0">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-exclamation-triangle"
                        :title="__('Errors')"
                        :note="__('Failures for this site — newest first. Dismiss what you’ve handled; retry where supported.')"
                        tone="danger"
                    />

                    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        <x-server-workspace-tablist
                            :aria-label="__('Errors workspace sections')"
                            scroll
                            bare class="!mb-0 w-full"
                        >
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
                    </div>

                    {{-- Per-tab skeleton: Stream = chips + rows; Notifications = strip + list/form. --}}
                    @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
                    @foreach (['stream', 'notifications'] as $skeletonTab)
                        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setErrorsWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                            <span class="sr-only">{{ __('Loading section…') }}</span>
                            @if ($skeletonTab === 'stream')
                                <div class="flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
                                    @foreach ([16, 20, 14, 18, 16] as $chip)
                                        <span class="h-6 rounded-full {{ $bar }}" style="width: {{ $chip * 4 }}px;"></span>
                                    @endforeach
                                </div>
                                <div class="divide-y divide-brand-ink/10" aria-hidden="true">
                                    @foreach (range(1, 5) as $row)
                                        <div class="flex items-start gap-2.5 px-3 py-2.5 sm:px-4">
                                            <span class="h-6 w-6 shrink-0 rounded-full {{ $bar }}"></span>
                                            <div class="min-w-0 flex-1 space-y-1.5">
                                                <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                                                <div class="h-2 w-2/3 {{ $bar }}"></div>
                                            </div>
                                            <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
                                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                                    <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                                    <span class="h-6 w-32 shrink-0 rounded-lg {{ $bar }}"></span>
                                </div>
                                <div class="divide-y divide-brand-ink/10" aria-hidden="true">
                                    @foreach (range(1, 2) as $channel)
                                        <div class="flex items-center justify-between gap-3 px-3 py-2.5 sm:px-4">
                                            <div class="min-w-0 flex-1 space-y-1.5">
                                                <div class="h-2.5 w-36 max-w-full {{ $bar }}"></div>
                                                <div class="h-2 w-16 {{ $bar }}"></div>
                                            </div>
                                            <span class="h-5 w-24 shrink-0 rounded-full {{ $bar }}"></span>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="grid gap-3 border-t border-brand-ink/10 px-3 py-3 sm:grid-cols-2 sm:px-4" aria-hidden="true">
                                    @foreach (range(1, 2) as $field)
                                        <div class="space-y-1.5">
                                            <div class="h-2.5 w-16 {{ $bar }}"></div>
                                            <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div wire:loading.class="hidden" wire:target="setErrorsWorkspaceTab" class="min-w-0">
                        @if ($errorsTab === 'stream')
                            @include('livewire.sites.partials.errors.reference-lookup')
                            @include('livewire.partials.error-stream', ['errorStreamNested' => true])

                            @php
                                // Everything this tab does, from a terminal. `errors` is
                                // site-kind agnostic, so one set covers vm/cloud/edge/
                                // serverless; the function-only drill-down is appended
                                // for functions. Ids come from the list rows above.
                                $cliSite = $site->slug;
                                $cliExampleId = $this->events->first()?->id ?? '<id>';
                                $cliCommands = [
                                    ['label' => __('Open errors, newest first'), 'command' => 'dply sites:errors '.$cliSite],
                                    ['label' => __('With detail + remediation code'), 'command' => 'dply sites:errors '.$cliSite.' --full'],
                                    ['label' => __('Watch for new events'), 'command' => 'dply sites:errors '.$cliSite.' --watch'],
                                    ['label' => __('One category only'), 'command' => 'dply sites:errors '.$cliSite.' --category deploy,ssl'],
                                    ['label' => __('Dismiss one · every open one'), 'command' => 'dply errors dismiss '.$cliExampleId.' --site '.$cliSite],
                                    ['label' => __('Dismiss all'), 'command' => 'dply errors dismiss --all --site '.$cliSite],
                                    ['label' => __('Retry the failed operation'), 'command' => 'dply errors retry '.$cliExampleId.' --site '.$cliSite],
                                    ['label' => __('Apply the known fix'), 'command' => 'dply errors fix '.$cliExampleId.' --site '.$cliSite],
                                    ['label' => __('Raw payload for scripts'), 'command' => 'dply sites:errors '.$cliSite.' --json'],
                                    ['label' => __('Gate a deploy on a clean site (exits 1 when any are open)'), 'command' => 'dply deploy --wait && dply errors '.$cliSite.' --no-prompt'],
                                ];
                            @endphp

                            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
                                <x-cli-snippet
                                    :commands="$cliCommands"
                                    :intro="__('Run these anywhere the CLI is signed in. On a terminal, leaving the id off opens a picker.')"
                                />
                            </div>
                        @endif

                        @if ($errorsTab === 'notifications')
                            @include('livewire.sites.partials.errors.notifications-tab')
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
    @include('livewire.partials.create-notification-channel-modal')
    @include('livewire.partials.error-logs-drawer')
</div>
