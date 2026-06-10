<x-section-card>
    <h3 class="text-base font-semibold text-brand-ink">{{ __('How to use operations') }}</h3>
    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('This tab is for day-two work: reviewing what changed, seeing whether the grouped resources are healthy, capturing runbooks, and routing the right alerts. Check here first during incident response or before planned maintenance.') }}</p>
</x-section-card>

<x-section-card tone="subtle">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-2xl">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Operational readiness') }}</h3>
            <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('Use this quick check before a risky deploy or while handling an incident. A strong project should have health visibility, at least one recovery runbook, and notification routing that reaches the right team.') }}</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-3 lg:min-w-[24rem]">
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Runbooks') }}</p>
                <p class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice(':count ready|:count ready', $operationsSummary['runbook_count'], ['count' => $operationsSummary['runbook_count']]) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Notification routes') }}</p>
                <p class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice(':count saved|:count saved', $operationsSummary['notification_route_count'], ['count' => $operationsSummary['notification_route_count']]) }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ trans_choice(':count event covered|:count events covered', $operationsSummary['notification_event_count'], ['count' => $operationsSummary['notification_event_count']]) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Health') }}</p>
                <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $health['status_label'] }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Monitored servers: :count', ['count' => $operationsSummary['monitored_servers']]) }}</p>
            </div>
        </div>
    </div>
</x-section-card>

<div class="grid gap-8 xl:grid-cols-2">
    <div class="space-y-8">
        <x-section-card>
            <div class="mb-4">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Runbooks') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Use runbooks for links and notes that help operators recover quickly: DNS notes, rollback steps, vendor dashboards, incident docs, escalation contacts, and recurring procedures.') }}</p>
            </div>
            <div class="mb-5 space-y-3">
                @forelse ($workspace->runbooks as $runbook)
                    <div class="rounded-xl border border-brand-ink/10 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-medium text-brand-ink">{{ $runbook->title }}</p>
                            @can('update', $workspace)
                                <button type="button" wire:click="removeRunbook('{{ $runbook->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                            @endcan
                        </div>
                        @if ($runbook->url)
                            <p class="mt-1 text-sm"><a href="{{ $runbook->url }}" class="text-brand-ink underline" target="_blank" rel="noreferrer">{{ $runbook->url }}</a></p>
                        @endif
                        @if ($runbook->body)
                            <p class="mt-2 whitespace-pre-line text-sm text-brand-moss">{{ $runbook->body }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('No runbooks yet.') }}</p>
                @endforelse
            </div>

            @can('update', $workspace)
                <div class="space-y-3">
                    <div>
                        <x-input-label for="runbook-title" :value="__('Title')" />
                        <x-text-input id="runbook-title" wire:model="runbookTitle" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="runbook-url" :value="__('URL (optional)')" />
                        <x-text-input id="runbook-url" wire:model="runbookUrl" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="runbook-body" :value="__('Notes')" />
                        <x-textarea id="runbook-body" wire:model="runbookBody" rows="4" />
                    </div>
                </div>
                <div class="mt-3">
                    <x-secondary-button type="button" wire:click="addRunbook">{{ __('Save runbook') }}</x-secondary-button>
                </div>
            @endcan
        </x-section-card>

        <x-section-card>
            <div class="mb-4">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Project notifications') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Choose where project-level alerts and summaries should go. This is best for escalation channels, team inboxes, or webhook sinks that should receive grouped project events.') }}</p>
            </div>
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Saved routes') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $operationsSummary['notification_route_count'] }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Event coverage') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ trans_choice(':count event|:count events', $operationsSummary['notification_event_count'], ['count' => $operationsSummary['notification_event_count']]) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Suggested use') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">
                        {{ $operationsSummary['notification_route_count'] > 0 ? __('Escalation ready') : __('Needs a destination') }}
                    </p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <p class="mb-2 text-sm font-medium text-brand-ink">{{ __('Channels') }}</p>
                    <div class="max-h-40 space-y-2 overflow-y-auto">
                        @foreach ($assignableChannels as $channel)
                            <label class="flex items-center gap-3 text-sm">
                                <input type="checkbox" wire:model="selectedProjectChannelIds" value="{{ $channel->id }}" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage/40">
                                <span>{{ $channel->label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium text-brand-ink">{{ __('Events') }}</p>
                    <div class="space-y-2">
                        @foreach (['project.deployments' => __('Project deploys'), 'project.health' => __('Health alerts'), 'project.activity' => __('Activity summaries')] as $eventKey => $eventLabel)
                            <label class="flex items-center gap-3 text-sm">
                                <input type="checkbox" wire:model="selectedProjectEventKeys" value="{{ $eventKey }}" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage/40">
                                <span>{{ $eventLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @can('update', $workspace)
                    <div>
                        <x-secondary-button type="button" wire:click="saveNotifications">{{ __('Save notification routing') }}</x-secondary-button>
                    </div>
                @endcan
            </div>
        </x-section-card>
    </div>

    <div class="space-y-8">
        <x-section-card>
            <div class="mb-4">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Activity feed') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Review recent project-level changes here before making updates. This helps you confirm whether someone already changed access, attached resources, or queued work recently.') }}</p>
            </div>
            <div class="space-y-3">
                @forelse ($activity as $item)
                    @php($event = $item['event'])
                    <div class="rounded-xl border border-brand-ink/10 px-4 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <p class="text-sm font-medium text-brand-ink">{{ $event->action_summary }}</p>
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" wire:navigate class="text-xs font-medium text-brand-moss hover:text-brand-ink">
                                    {{ $item['linkLabel'] }}
                                </a>
                            @endif
                        </div>
                        @if ($event->user)
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('By :name', ['name' => $event->user->name]) }}
                            </p>
                        @endif
                        @if ($event->subject_summary)
                            <p class="text-sm text-brand-moss">{{ $event->subject_summary }}</p>
                        @endif
                        <p class="mt-1 text-xs text-brand-moss">{{ $event->created_at?->diffForHumans() }}</p>
                    </div>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('No project activity yet.') }}</p>
                @endforelse
            </div>
        </x-section-card>

        <x-section-card>
            <div class="mb-4">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Health summary') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Use this summary to spot trouble before drilling into individual servers and sites. If this section looks healthy, the project is usually safe to leave alone.') }}</p>
            </div>
            <div class="mb-4 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Monitored servers') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __(':count / :total', ['count' => $operationsSummary['monitored_servers'], 'total' => $workspace->servers->count()]) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Servers with samples') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ __(':count / :total', ['count' => $operationsSummary['servers_with_samples'], 'total' => $operationsSummary['monitored_servers']]) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Pending deploys') }}</p>
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $health['pending_deploys'] }}</p>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Servers') }}</p>
                    <p class="mt-2 text-sm text-brand-ink">{{ __('Ready: :count / :total', ['count' => $health['servers_ready'], 'total' => $health['servers_total']]) }}</p>
                    <p class="mt-1 text-sm text-brand-ink">{{ __('Unreachable: :count', ['count' => $health['servers_unreachable']]) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Sites') }}</p>
                    <p class="mt-2 text-sm text-brand-ink">{{ __('SSL active: :count / :total', ['count' => $health['sites_active_ssl'], 'total' => $health['sites_total']]) }}</p>
                    <p class="mt-1 text-sm text-brand-ink">{{ __('Errors: :count', ['count' => $health['sites_error']]) }}</p>
                </div>
            </div>
            @if ($workspace->servers->isNotEmpty() || $workspace->sites->isNotEmpty())
                <div class="mt-5 space-y-3 border-t border-brand-ink/10 pt-5">
                    <p class="text-sm font-medium text-brand-ink">{{ __('Drill into resource operations') }}</p>
                    <div class="flex flex-wrap gap-2 text-xs text-brand-moss">
                        @foreach ($workspace->servers->take(3) as $server)
                            <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="rounded-full border border-brand-ink/10 px-3 py-1 hover:bg-brand-sand/40 hover:text-brand-ink">
                                {{ $server->name }}: {{ __('metrics') }}
                            </a>
                            <a href="{{ route('servers.logs', $server) }}" wire:navigate class="rounded-full border border-brand-ink/10 px-3 py-1 hover:bg-brand-sand/40 hover:text-brand-ink">
                                {{ $server->name }}: {{ __('logs') }}
                            </a>
                        @endforeach
                        @foreach ($workspace->sites->take(3) as $site)
                            <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="rounded-full border border-brand-ink/10 px-3 py-1 hover:bg-brand-sand/40 hover:text-brand-ink">
                                {{ $site->name }}: {{ __('site page') }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-section-card>
    </div>
</div>
