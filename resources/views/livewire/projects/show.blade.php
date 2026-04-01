<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
            <x-page-header
                :title="$workspace->name"
                :description="__('Operate one logical stack, customer, or app family from a shared project workspace.')"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Projects') }} <span aria-hidden="true">←</span></a>
                    @can('delete', $workspace)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('destroyWorkspace', [], @js(__('Delete project')), @js(__('Delete this project? Servers and sites stay in your organization; only the group is removed.')), @js(__('Delete project')), true)"
                            class="text-sm font-medium text-red-600 hover:text-red-800"
                        >
                            {{ __('Delete project') }}
                        </button>
                    @endcan
                </x-slot>
            </x-page-header>

            @if (session('success'))
                <x-alert tone="success">{{ session('success') }}</x-alert>
            @endif
            <x-livewire-validation-errors />

            <div class="grid gap-4 md:grid-cols-4">
                <x-stat-card :label="__('Health')" :value="$health['status_label']" :meta="$health['servers_ready'].'/'.$health['servers_total'].' '.__('servers ready')" />
                <x-stat-card :label="__('Sites')" :value="$costSummary['sites_used']" :meta="__('Remaining in org plan: :count', ['count' => $costSummary['sites_remaining']])" />
                <x-stat-card :label="__('Servers')" :value="$costSummary['servers_used']" :meta="__('Remaining in org plan: :count', ['count' => $costSummary['servers_remaining']])" />
                <x-stat-card :label="__('Deploy runs')" :value="$costSummary['deploy_runs_count']" :meta="__('Variables: :count', ['count' => $costSummary['variables_count']])" />
            </div>

            <x-section-card tone="subtle">
                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Projects are for day-two operations, not just grouping') }}</h3>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Use a project as the shared operating surface for one app, customer, or environment family. Keep runbooks, health, release context, notification routing, and shared variables here so recovery and change management do not depend on one person remembering the setup.') }}
                </p>
            </x-section-card>

            @if ($health['issues'] !== [])
                <x-alert tone="warning">
                    <p class="font-medium">{{ __('Needs attention') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($health['issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <x-server-workspace-tablist aria-label="{{ __('Project sections') }}">
                <x-server-workspace-tab as="a" href="{{ route('projects.overview', $workspace) }}" wire:navigate :active="$section === 'overview'">{{ __('Overview') }}</x-server-workspace-tab>
                <x-server-workspace-tab as="a" href="{{ route('projects.resources', $workspace) }}" wire:navigate :active="$section === 'resources'">{{ __('Resources') }}</x-server-workspace-tab>
                <x-server-workspace-tab as="a" href="{{ route('projects.access', $workspace) }}" wire:navigate :active="$section === 'access'">{{ __('Access') }}</x-server-workspace-tab>
                <x-server-workspace-tab as="a" href="{{ route('projects.operations', $workspace) }}" wire:navigate :active="$section === 'operations'">{{ __('Operations') }}</x-server-workspace-tab>
                <x-server-workspace-tab as="a" href="{{ route('projects.delivery', $workspace) }}" wire:navigate :active="$section === 'delivery'">{{ __('Delivery') }}</x-server-workspace-tab>
            </x-server-workspace-tablist>

            @if ($section === 'overview')
            <x-server-workspace-tab-panel id="project-overview" labelled-by="project-overview-tab" panelClass="space-y-8">
                <div class="bg-white border border-slate-200 shadow-sm rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('How to use this project') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ __('Use this page as the control center for one logical stack, customer, or app family. A project should answer three questions quickly: what resources belong together, who can operate them, and what needs attention right now.') }}
                    </p>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('1. Define the project') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Add a clear description, architecture notes, labels, and environments so other teammates know what this project exists for.') }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('2. Attach the right resources') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Group the servers and sites that belong together. Think of a project as a managed bundle, not just a tag.') }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('3. Operate from one place') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Use activity, alerts, variables, and deploy batches here before diving into individual server or site pages.') }}</p>
                        </div>
                    </div>
                </div>

                @can('update', $workspace)
                    <div class="bg-white border border-slate-200 shadow-sm rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Details and notes') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Start here when creating a new project. The description should explain the business purpose; notes should capture the operational context someone needs when they open this page later.') }}</p>
                        </div>
                        <form wire:submit="saveDetails" class="space-y-4">
                            <div>
                                <x-input-label for="edit-name" :value="__('Name')" />
                                <x-text-input id="edit-name" wire:model="editName" type="text" class="mt-1 block w-full" required />
                                <x-input-error :messages="$errors->get('editName')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="edit-desc" :value="__('Description')" />
                                <x-textarea id="edit-desc" wire:model="editDescription" rows="3" />
                                <p class="mt-1 text-xs text-slate-500">{{ __('Example: Customer production stack, marketing properties, or internal staging fleet.') }}</p>
                                <x-input-error :messages="$errors->get('editDescription')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="edit-notes" :value="__('Project notes')" />
                                <x-textarea id="edit-notes" wire:model="editNotes" rows="6" />
                                <p class="mt-1 text-xs text-slate-500">{{ __('Use notes for architecture context, provider quirks, DNS assumptions, incident history, customer handoff details, and anything operators should know before making changes.') }}</p>
                                <x-input-error :messages="$errors->get('editNotes')" class="mt-2" />
                            </div>
                            <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                        </form>
                    </div>
                @endcan

                <div class="grid gap-8 xl:grid-cols-2">
                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Environments') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Use environments to explain how resources are used inside the project, such as production, staging, or QA. They help teammates understand the intended lifecycle even when multiple sites or servers live in the same project.') }}</p>
                        </div>
                        <div class="space-y-3 mb-5">
                            @foreach ($workspace->environments as $environment)
                                <div class="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-3">
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $environment->name }}</p>
                                        @if ($environment->description)
                                            <p class="text-sm text-slate-500">{{ $environment->description }}</p>
                                        @endif
                                    </div>
                                    @can('update', $workspace)
                                        <button type="button" wire:click="removeEnvironment('{{ $environment->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                                    @endcan
                                </div>
                            @endforeach
                        </div>

                        @can('update', $workspace)
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <x-input-label for="environment-name" :value="__('Environment name')" />
                                    <x-text-input id="environment-name" wire:model="environmentName" type="text" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="environment-description" :value="__('Description')" />
                                    <x-text-input id="environment-description" wire:model="environmentDescription" type="text" class="mt-1 block w-full" />
                                </div>
                            </div>
                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="addEnvironment">{{ __('Add environment') }}</x-secondary-button>
                            </div>
                        @endcan
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Labels and organization') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Labels are best for quick filtering across many projects. Use them for customer names, app type, criticality, or ownership, while keeping the project itself as the main grouping container.') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-5">
                            @foreach ($labels as $label)
                                @php($attached = $workspace->labels->contains('id', $label->id))
                                <button type="button" wire:click="toggleLabel('{{ $label->id }}')" class="rounded-full px-3 py-1 text-xs border {{ $attached ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-300' }}">
                                    {{ $label->name }}
                                </button>
                            @endforeach
                        </div>

                        @can('update', $workspace)
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <x-input-label for="label-name" :value="__('New label')" />
                                    <x-text-input id="label-name" wire:model="labelName" type="text" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="label-color" :value="__('Color name')" />
                                    <x-text-input id="label-color" wire:model="labelColor" type="text" class="mt-1 block w-full" placeholder="slate" />
                                </div>
                            </div>
                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="createLabel">{{ __('Create label') }}</x-secondary-button>
                            </div>
                        @endcan
                    </div>
                </div>
            </x-server-workspace-tab-panel>
            @endif

            @if ($section === 'resources')
            <x-server-workspace-tab-panel id="project-resources" labelled-by="project-resources-tab" panelClass="space-y-8">
                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('How to use resources') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Attach every server and site that belongs to this project. Use this tab when you want one shared home for a stack, customer account, product area, or environment cluster. Remove resources when they no longer belong operationally, not just because they are quiet.') }}</p>
                </div>

                <div class="grid gap-8 lg:grid-cols-2">
                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Servers in this project') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Attach infrastructure that is primarily operated as part of this project. This makes it easier to review health, ownership, and related sites together.') }}</p>
                        </div>
                        @if ($workspace->servers->isEmpty())
                            <p class="text-sm text-slate-500 mb-4">{{ __('No servers yet.') }}</p>
                        @else
                            <ul class="divide-y divide-slate-100 mb-4">
                                @foreach ($workspace->servers as $server)
                                    <li class="py-3 flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <a href="{{ route('servers.show', $server) }}" class="text-slate-900 font-medium hover:underline">{{ $server->name }}</a>
                                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                                <a href="{{ route('servers.show', $server) }}" class="hover:text-slate-800">{{ __('Overview') }}</a>
                                                <a href="{{ route('servers.logs', $server) }}" wire:navigate class="hover:text-slate-800">{{ __('Logs') }}</a>
                                                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="hover:text-slate-800">{{ __('Metrics') }}</a>
                                                <a href="{{ route('servers.services', $server) }}" wire:navigate class="hover:text-slate-800">{{ __('Services') }}</a>
                                                <a href="{{ route('servers.manage', $server) }}" wire:navigate class="hover:text-slate-800">{{ __('Manage') }}</a>
                                            </div>
                                        </div>
                                        @can('update', $server)
                                            <button type="button" wire:click="detachServer({{ $server->id }})" class="text-xs text-slate-500 hover:text-red-600">{{ __('Remove') }}</button>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @can('update', $workspace)
                            @if ($availableServers->isNotEmpty())
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="flex-1 min-w-[12rem]">
                                        <x-input-label for="server-pick" :value="__('Add server')" />
                                        <x-select id="server-pick" wire:model="serverToAttach">
                                            <option value="">{{ __('Choose...') }}</option>
                                            @foreach ($availableServers as $s)
                                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                            @endforeach
                                        </x-select>
                                    </div>
                                    <x-secondary-button type="button" wire:click="attachServer">{{ __('Add') }}</x-secondary-button>
                                </div>
                            @else
                                <p class="text-xs text-slate-400">{{ __('All servers in this organization are already in this project, or you have no servers yet.') }}</p>
                            @endif
                        @endcan
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Sites in this project') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Attach sites that should deploy, alert, and be reviewed alongside this project. This is useful for multi-site apps, customer estates, and grouped environments.') }}</p>
                        </div>
                        @if ($workspace->sites->isEmpty())
                            <p class="text-sm text-slate-500 mb-4">{{ __('No sites yet.') }}</p>
                        @else
                            <ul class="divide-y divide-slate-100 mb-4">
                                @foreach ($workspace->sites as $site)
                                    <li class="py-3 flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <a href="{{ route('sites.show', [$site->server, $site]) }}" class="text-slate-900 font-medium hover:underline">{{ $site->name }}</a>
                                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                                <a href="{{ route('sites.show', [$site->server, $site]) }}" class="hover:text-slate-800">{{ __('Overview') }}</a>
                                                <a href="{{ route('sites.insights', [$site->server, $site]) }}" wire:navigate class="hover:text-slate-800">{{ __('Insights') }}</a>
                                            </div>
                                        </div>
                                        @can('update', $site)
                                            <button type="button" wire:click="detachSite({{ $site->id }})" class="text-xs text-slate-500 hover:text-red-600">{{ __('Remove') }}</button>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @can('update', $workspace)
                            @if ($availableSites->isNotEmpty())
                                <div class="flex flex-wrap items-end gap-2">
                                    <div class="flex-1 min-w-[12rem]">
                                        <x-input-label for="site-pick" :value="__('Add site')" />
                                        <x-select id="site-pick" wire:model="siteToAttach">
                                            <option value="">{{ __('Choose...') }}</option>
                                            @foreach ($availableSites as $s)
                                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                            @endforeach
                                        </x-select>
                                    </div>
                                    <x-secondary-button type="button" wire:click="attachSite">{{ __('Add') }}</x-secondary-button>
                                </div>
                            @else
                                <p class="text-xs text-slate-400">{{ __('All sites are already in this project, or you have no sites yet.') }}</p>
                            @endif
                        @endcan
                    </div>
                </div>
            </x-server-workspace-tab-panel>
            @endif

            @if ($section === 'access')
            <x-server-workspace-tab-panel id="project-access" labelled-by="project-access-tab" panelClass="space-y-8">
                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('How to use access') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Keep access here as narrow as possible. Add only the people who should work on this project. Use owners for long-term accountability, maintainers for day-to-day changes, deployers for release execution, and viewers for read-only visibility.') }}</p>
                </div>

                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="font-medium text-slate-900">{{ __('Access') }}</h3>
                        <span class="text-xs uppercase tracking-wide text-slate-500">{{ __('Strict project membership') }}</span>
                    </div>

                    <div class="space-y-3 mb-5">
                        @foreach ($workspace->members as $member)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 px-3 py-3">
                                <div>
                                    <p class="font-medium text-slate-900">{{ $member->user?->name }}</p>
                                    <p class="text-sm text-slate-500">{{ $member->user?->email }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{{ ucfirst($member->role) }}</span>
                                    @if ($workspace->userCanManageMembers(auth()->user()))
                                        <button type="button" wire:click="removeMember('{{ $member->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($workspace->userCanManageMembers(auth()->user()))
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4 text-sm text-slate-600">
                            {{ __('Add organization members here when they should be able to work inside this project. If someone only needs access to one customer or one stack, prefer project membership over broader organization permissions.') }}
                        </div>
                        <div class="grid gap-3 md:grid-cols-3">
                            <div class="md:col-span-2">
                                <x-input-label for="member-user" :value="__('Add member')" />
                                <x-select id="member-user" wire:model="memberUserId">
                                    <option value="">{{ __('Choose organization member') }}</option>
                                    @foreach ($orgUsers as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div>
                                <x-input-label for="member-role" :value="__('Role')" />
                                <x-select id="member-role" wire:model="memberRole">
                                    @foreach ($workspaceRoles as $role)
                                        <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <x-secondary-button type="button" wire:click="addMember">{{ __('Save member') }}</x-secondary-button>
                        </div>
                    @endif
                </div>
            </x-server-workspace-tab-panel>
            @endif

            @if ($section === 'operations')
            <x-server-workspace-tab-panel id="project-operations" labelled-by="project-operations-tab" panelClass="space-y-8">
                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('How to use operations') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('This tab is for day-two work: reviewing what changed, seeing whether the grouped resources are healthy, capturing runbooks, and routing the right alerts. Check here first during incident response or before planned maintenance.') }}</p>
                </div>

                <div class="bg-slate-50 border border-slate-200 rounded-lg p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-2xl">
                            <h3 class="font-medium text-slate-900">{{ __('Operational readiness') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Use this quick check before a risky deploy or while handling an incident. A strong project should have health visibility, at least one recovery runbook, and notification routing that reaches the right team.') }}</p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3 lg:min-w-[24rem]">
                            <div class="rounded-lg border border-slate-200 bg-white p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Runbooks') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ trans_choice(':count ready|:count ready', $operationsSummary['runbook_count'], ['count' => $operationsSummary['runbook_count']]) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Notification routes') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ trans_choice(':count saved|:count saved', $operationsSummary['notification_route_count'], ['count' => $operationsSummary['notification_route_count']]) }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ trans_choice(':count event covered|:count events covered', $operationsSummary['notification_event_count'], ['count' => $operationsSummary['notification_event_count']]) }}</p>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-white p-4">
                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Health') }}</p>
                                <p class="mt-2 text-sm font-semibold text-slate-900">{{ $health['status_label'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ __('Monitored servers: :count', ['count' => $operationsSummary['monitored_servers']]) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-8 xl:grid-cols-2">
                    <div class="space-y-8">
                        <div class="bg-white border border-slate-200 rounded-lg p-6">
                            <div class="mb-4">
                                <h3 class="font-medium text-slate-900">{{ __('Runbooks') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Use runbooks for links and notes that help operators recover quickly: DNS notes, rollback steps, vendor dashboards, incident docs, escalation contacts, and recurring procedures.') }}</p>
                            </div>
                            <div class="space-y-3 mb-5">
                                @forelse ($workspace->runbooks as $runbook)
                                    <div class="rounded-md border border-slate-200 px-4 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="font-medium text-slate-900">{{ $runbook->title }}</p>
                                            @can('update', $workspace)
                                                <button type="button" wire:click="removeRunbook('{{ $runbook->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                                            @endcan
                                        </div>
                                        @if ($runbook->url)
                                            <p class="mt-1 text-sm"><a href="{{ $runbook->url }}" class="text-slate-700 underline" target="_blank" rel="noreferrer">{{ $runbook->url }}</a></p>
                                        @endif
                                        @if ($runbook->body)
                                            <p class="mt-2 text-sm text-slate-600 whitespace-pre-line">{{ $runbook->body }}</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">{{ __('No runbooks yet.') }}</p>
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
                        </div>

                        <div class="bg-white border border-slate-200 rounded-lg p-6">
                            <div class="mb-4">
                                <h3 class="font-medium text-slate-900">{{ __('Project notifications') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Choose where project-level alerts and summaries should go. This is best for escalation channels, team inboxes, or webhook sinks that should receive grouped project events.') }}</p>
                            </div>
                            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Saved routes') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $operationsSummary['notification_route_count'] }}</p>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Event coverage') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ trans_choice(':count event|:count events', $operationsSummary['notification_event_count'], ['count' => $operationsSummary['notification_event_count']]) }}</p>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Suggested use') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                        {{ $operationsSummary['notification_route_count'] > 0 ? __('Escalation ready') : __('Needs a destination') }}
                                    </p>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 mb-2">{{ __('Channels') }}</p>
                                    <div class="space-y-2 max-h-40 overflow-y-auto">
                                        @foreach ($assignableChannels as $channel)
                                            <label class="flex items-center gap-3 text-sm">
                                                <input type="checkbox" wire:model="selectedProjectChannelIds" value="{{ $channel->id }}" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                                <span>{{ $channel->label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-slate-900 mb-2">{{ __('Events') }}</p>
                                    <div class="space-y-2">
                                        @foreach (['project.deployments' => __('Project deploys'), 'project.health' => __('Health alerts'), 'project.activity' => __('Activity summaries')] as $eventKey => $eventLabel)
                                            <label class="flex items-center gap-3 text-sm">
                                                <input type="checkbox" wire:model="selectedProjectEventKeys" value="{{ $eventKey }}" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
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
                        </div>
                    </div>

                    <div class="space-y-8">
                        <div class="bg-white border border-slate-200 rounded-lg p-6">
                            <div class="mb-4">
                                <h3 class="font-medium text-slate-900">{{ __('Activity feed') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Review recent project-level changes here before making updates. This helps you confirm whether someone already changed access, attached resources, or queued work recently.') }}</p>
                            </div>
                            <div class="space-y-3">
                                @forelse ($activity as $item)
                                    @php($event = $item['event'])
                                    <div class="rounded-md border border-slate-200 px-4 py-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <p class="text-sm font-medium text-slate-900">{{ $event->action_summary }}</p>
                                            @if ($item['url'])
                                                <a href="{{ $item['url'] }}" wire:navigate class="text-xs font-medium text-slate-600 hover:text-slate-900">
                                                    {{ $item['linkLabel'] }}
                                                </a>
                                            @endif
                                        </div>
                                        @if ($event->user)
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ __('By :name', ['name' => $event->user->name]) }}
                                            </p>
                                        @endif
                                        @if ($event->subject_summary)
                                            <p class="text-sm text-slate-600">{{ $event->subject_summary }}</p>
                                        @endif
                                        <p class="text-xs text-slate-500 mt-1">{{ $event->created_at?->diffForHumans() }}</p>
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-500">{{ __('No project activity yet.') }}</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="bg-white border border-slate-200 rounded-lg p-6">
                            <div class="mb-4">
                                <h3 class="font-medium text-slate-900">{{ __('Health summary') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Use this summary to spot trouble before drilling into individual servers and sites. If this section looks healthy, the project is usually safe to leave alone.') }}</p>
                            </div>
                            <div class="mb-4 grid gap-4 sm:grid-cols-3">
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Monitored servers') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ __(':count / :total', ['count' => $operationsSummary['monitored_servers'], 'total' => $workspace->servers->count()]) }}</p>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Servers with samples') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ __(':count / :total', ['count' => $operationsSummary['servers_with_samples'], 'total' => $operationsSummary['monitored_servers']]) }}</p>
                                </div>
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Pending deploys') }}</p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $health['pending_deploys'] }}</p>
                                </div>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-lg border border-slate-200 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Servers') }}</p>
                                    <p class="mt-2 text-sm text-slate-700">{{ __('Ready: :count / :total', ['count' => $health['servers_ready'], 'total' => $health['servers_total']]) }}</p>
                                    <p class="mt-1 text-sm text-slate-700">{{ __('Unreachable: :count', ['count' => $health['servers_unreachable']]) }}</p>
                                </div>
                                <div class="rounded-lg border border-slate-200 p-4">
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('Sites') }}</p>
                                    <p class="mt-2 text-sm text-slate-700">{{ __('SSL active: :count / :total', ['count' => $health['sites_active_ssl'], 'total' => $health['sites_total']]) }}</p>
                                    <p class="mt-1 text-sm text-slate-700">{{ __('Errors: :count', ['count' => $health['sites_error']]) }}</p>
                                </div>
                            </div>
                            @if ($workspace->servers->isNotEmpty() || $workspace->sites->isNotEmpty())
                                <div class="mt-5 space-y-3 border-t border-slate-100 pt-5">
                                    <p class="text-sm font-medium text-slate-900">{{ __('Drill into resource operations') }}</p>
                                    <div class="flex flex-wrap gap-2 text-xs text-slate-600">
                                        @foreach ($workspace->servers->take(3) as $server)
                                            <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="rounded-full border border-slate-200 px-3 py-1 hover:border-slate-300 hover:text-slate-900">
                                                {{ $server->name }}: {{ __('metrics') }}
                                            </a>
                                            <a href="{{ route('servers.logs', $server) }}" wire:navigate class="rounded-full border border-slate-200 px-3 py-1 hover:border-slate-300 hover:text-slate-900">
                                                {{ $server->name }}: {{ __('logs') }}
                                            </a>
                                        @endforeach
                                        @foreach ($workspace->sites->take(3) as $site)
                                            <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="rounded-full border border-slate-200 px-3 py-1 hover:border-slate-300 hover:text-slate-900">
                                                {{ $site->name }}: {{ __('site page') }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-server-workspace-tab-panel>
            @endif

            @if ($section === 'delivery')
            <x-server-workspace-tab-panel id="project-delivery" labelled-by="project-delivery-tab" panelClass="space-y-8">
                <x-resource-notification-summary
                    :resource="$workspace"
                    :heading="__('Project notifications')"
                    :manage-url="route('projects.delivery', $workspace)"
                />

                <div class="bg-white border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('How to use delivery') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Use this tab when you want the project to coordinate releases across several sites. Save shared variables here before deploys, then queue one batch when multiple sites should move together.') }}</p>
                </div>

                <div class="bg-slate-50 border border-slate-200 rounded-lg p-6">
                    <h3 class="font-medium text-slate-900">{{ __('Recovery and migration checklist') }}</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('1. Shared config is ready') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Keep the variables and secrets this project needs in one place before rebuilding a server or moving traffic.') }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('2. Recovery steps are written down') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('Tie rollback notes, backup destinations, import commands, and cache-clear steps to project runbooks so another operator can take over.') }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <p class="text-sm font-medium text-slate-900">{{ __('3. Releases can move together') }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ __('When one deploy spans several sites, queue it here so release timing and follow-up checks stay grouped.') }}</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-8 xl:grid-cols-2">
                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Project variables and secrets') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Store values here when several sites in the project need the same configuration. Use secrets for credentials or tokens, and non-secret values for shared flags or endpoints.') }}</p>
                        </div>
                        <div class="space-y-3 mb-5">
                            @forelse ($workspace->variables as $variable)
                                <div class="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-3">
                                    <div>
                                        <p class="font-medium text-slate-900">{{ $variable->env_key }}</p>
                                        <p class="text-sm text-slate-500">{{ $variable->is_secret ? __('Secret value') : ($variable->env_value ?? __('Empty')) }}</p>
                                    </div>
                                    @can('update', $workspace)
                                        <button type="button" wire:click="deleteVariable('{{ $variable->id }}')" class="text-xs text-red-600 hover:text-red-800">{{ __('Remove') }}</button>
                                    @endcan
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('No shared variables yet.') }}</p>
                            @endforelse
                        </div>

                        @can('update', $workspace)
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <x-input-label for="variable-key" :value="__('Key')" />
                                    <x-text-input id="variable-key" wire:model="variableKey" type="text" class="mt-1 block w-full" />
                                </div>
                                <div>
                                    <x-input-label for="variable-value" :value="__('Value')" />
                                    <x-text-input id="variable-value" wire:model="variableValue" type="text" class="mt-1 block w-full" />
                                </div>
                            </div>
                            <label class="mt-3 inline-flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" wire:model="variableIsSecret" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                <span>{{ __('Treat as secret') }}</span>
                            </label>
                            <div class="mt-3">
                                <x-secondary-button type="button" wire:click="saveVariable">{{ __('Save variable') }}</x-secondary-button>
                            </div>
                        @endcan
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="mb-4">
                            <h3 class="font-medium text-slate-900">{{ __('Coordinated deploys') }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Select the sites that should deploy together, then queue a project deploy. This is useful when one release spans multiple apps, services, or frontends in the same project.') }}</p>
                        </div>
                        <div class="space-y-2 mb-4">
                            @forelse ($workspace->sites as $site)
                                <div class="rounded-md border border-slate-200 px-3 py-3">
                                    <label class="flex items-center gap-3 text-sm">
                                        <input type="checkbox" wire:model="selectedDeploySiteIds" value="{{ $site->id }}" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                                        <span class="font-medium text-slate-900">{{ $site->name }}</span>
                                    </label>
                                    <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 ps-6 text-xs text-slate-500">
                                        <a href="{{ route('sites.show', [$site->server, $site]) }}" wire:navigate class="hover:text-slate-800">{{ __('Open site') }}</a>
                                        <a href="{{ route('sites.insights', [$site->server, $site]) }}" wire:navigate class="hover:text-slate-800">{{ __('Insights') }}</a>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('No sites in this project yet.') }}</p>
                            @endforelse
                        </div>
                        @if ($workspace->userCanDeploy(auth()->user()))
                            <div class="mb-5">
                                <x-primary-button type="button" wire:click="queueWorkspaceDeploy">{{ __('Queue project deploy') }}</x-primary-button>
                            </div>
                        @endif

                        <div class="space-y-3">
                            @forelse ($workspace->deployRuns->take(5) as $run)
                                <div class="rounded-md border border-slate-200 px-4 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-medium text-slate-900">{{ ucfirst($run->status) }}</p>
                                        <p class="text-xs text-slate-500">{{ $run->created_at?->diffForHumans() }}</p>
                                    </div>
                                    @if ($run->result_summary)
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ __('Success: :success, skipped: :skipped, failed: :failed', ['success' => $run->result_summary['successful'] ?? 0, 'skipped' => $run->result_summary['skipped'] ?? 0, 'failed' => $run->result_summary['failed'] ?? 0]) }}
                                        </p>
                                    @endif
                                    @if ($run->output)
                                        <pre class="mt-2 whitespace-pre-wrap text-xs text-slate-600">{{ $run->output }}</pre>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-slate-500">{{ __('No project deploy runs yet.') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-server-workspace-tab-panel>
            @endif
        </div>
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
