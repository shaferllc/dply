@php
    $containerMeta = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
    $sourceSpec = is_array($containerMeta['source'] ?? null) ? $containerMeta['source'] : null;
    $isSourceMode = $sourceSpec !== null;
    $liveUrl = $site->containerLiveUrl();
    $backendLabel = match ($site->container_backend) {
        'digitalocean_app_platform' => 'DigitalOcean App Platform',
        'aws_app_runner' => 'AWS App Runner',
        default => $site->container_backend ?? '—',
    };
    $statusBadgeClass = match ($site->status) {
        \App\Models\Site::STATUS_CONTAINER_ACTIVE => 'bg-emerald-100 text-emerald-800',
        \App\Models\Site::STATUS_CONTAINER_PROVISIONING => 'bg-sky-100 text-sky-800',
        \App\Models\Site::STATUS_CONTAINER_FAILED => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $statusLabel = match ($site->status) {
        \App\Models\Site::STATUS_CONTAINER_ACTIVE => __('Active'),
        \App\Models\Site::STATUS_CONTAINER_PROVISIONING => __('Provisioning'),
        \App\Models\Site::STATUS_CONTAINER_FAILED => __('Failed'),
        default => str_replace('_', ' ', (string) $site->status),
    };
@endphp

@include('livewire.sites.partials.hybrid-edge-stack-panel')

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Dply cloud') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Container deployment') }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                @if ($isSourceMode)
                    {{ __('This site runs from a GitHub repo. The backend builds + deploys on every push to the chosen branch.') }}
                @else
                    {{ __('This site runs as a container on a managed backend. Roll out a new image tag or tear it down here.') }}
                @endif
            </p>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] {{ $statusBadgeClass }}">
            {{ $statusLabel }}
        </span>
    </div>

    @if (! empty($containerMeta['last_error']))
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900">
            <p class="font-semibold">{{ __('Last error') }}</p>
            <p class="mt-1 break-words">{{ $containerMeta['last_error'] }}</p>
            @if (! empty($containerMeta['last_error_at']))
                <p class="mt-1 text-rose-700">{{ __('At :at', ['at' => $containerMeta['last_error_at']]) }}</p>
            @endif
        </div>
    @endif

    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Backend') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $backendLabel }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Region') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $site->container_region ?: '—' }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Port') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ $site->container_port ?: '—' }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Instances') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ (int) ($containerMeta['instance_count'] ?? 1) }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Size') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">{{ ucfirst((string) ($containerMeta['size_tier'] ?? 'small')) }}</dd>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <dt class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Live URL') }}</dt>
            <dd class="mt-1 text-sm font-medium text-slate-900">
                @if ($liveUrl)
                    <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="break-all text-sky-700 hover:underline">{{ $liveUrl }}</a>
                @else
                    <span class="text-slate-500">{{ __('Pending — backend has not assigned an ingress URL yet.') }}</span>
                @endif
            </dd>
        </div>
    </dl>

    @if ($isSourceMode)
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Source') }}</p>
            <dl class="mt-2 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Repository') }}</dt>
                    <dd class="mt-1 break-all font-mono text-xs text-slate-900">
                        <a href="https://github.com/{{ $sourceSpec['repo'] ?? '' }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $sourceSpec['repo'] ?? '—' }}</a>
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Branch') }}</dt>
                    <dd class="mt-1 font-mono text-xs text-slate-900">{{ $sourceSpec['branch'] ?? 'main' }}</dd>
                </div>
                @if (! empty($sourceSpec['dockerfile_path']))
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Dockerfile') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-slate-900">{{ $sourceSpec['dockerfile_path'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Auto-deploy on push') }}</dt>
                    <dd class="mt-1 text-xs">
                        @if (! array_key_exists('deploy_on_push', $sourceSpec) || $sourceSpec['deploy_on_push'])
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('Enabled') }}</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-700">{{ __('Disabled') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-slate-500">{{ __('Push to the branch above to trigger a build and deploy. Or manually re-roll the latest commit:') }}</p>
                <button type="button" wire:click="redeployContainer" wire:loading.attr="disabled" wire:target="redeployContainer" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="redeployContainer">{{ __('Redeploy from latest') }}</span>
                    <span wire:loading wire:target="redeployContainer">{{ __('Queueing…') }}</span>
                </button>
            </div>
        </div>
    @else
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <label for="container_image_input" class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Image reference') }}</label>
            <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
                <input id="container_image_input" wire:model="container_image_input" type="text" class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="ghcr.io/acme/api:v1.2.3" />
                <button type="button" wire:click="redeployContainer" wire:loading.attr="disabled" wire:target="redeployContainer" class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="redeployContainer">{{ __('Redeploy') }}</span>
                    <span wire:loading wire:target="redeployContainer">{{ __('Queueing…') }}</span>
                </button>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Update the tag and click Redeploy to roll a new revision. Leave the tag the same to just re-pull.') }}</p>
        </div>
    @endif

    @php
        $imageHistory = is_array($containerMeta['image_history'] ?? null) ? array_reverse($containerMeta['image_history']) : [];
    @endphp
    @if (! $isSourceMode && count($imageHistory) > 1)
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Image history') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Click Roll back to redeploy a previous image tag. The current tag is highlighted.') }}</p>
            <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($imageHistory as $entry)
                    @php
                        $isCurrent = ($entry['image'] ?? null) === $site->container_image;
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-xs {{ $isCurrent ? 'bg-emerald-50/40' : '' }}">
                        <div class="min-w-0">
                            <p class="break-all font-mono text-slate-900">{{ $entry['image'] ?? '—' }}</p>
                            @if (! empty($entry['deployed_at']))
                                <p class="mt-0.5 text-[10px] text-slate-500">{{ __('Deployed :at', ['at' => $entry['deployed_at']]) }}</p>
                            @endif
                        </div>
                        @if ($isCurrent)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800">{{ __('Current') }}</span>
                        @else
                            <button type="button" wire:click="rollbackContainerImage('{{ $entry['image'] }}')" wire:confirm="{{ __('Roll back to :img? The backend will redeploy with this tag.', ['img' => $entry['image']]) }}" class="text-xs font-medium text-sky-700 hover:text-sky-900">{{ __('Roll back') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Environment variables') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Edit and click Save & redeploy. The backend\'s spec is updated and a fresh roll is queued.') }}</p>
            </div>
            <button type="button" wire:click="saveContainerEnvAndRedeploy" wire:loading.attr="disabled" wire:target="saveContainerEnvAndRedeploy" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-900 disabled:opacity-50">
                <span wire:loading.remove wire:target="saveContainerEnvAndRedeploy">{{ __('Save & redeploy') }}</span>
                <span wire:loading wire:target="saveContainerEnvAndRedeploy">{{ __('Saving…') }}</span>
            </button>
        </div>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div>
                <label for="container_env_file_input" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Runtime') }}</label>
                <p class="mt-0.5 text-[11px] text-slate-500">{{ __('Available at run-time only.') }}</p>
                <textarea id="container_env_file_input" wire:model="container_env_file_input" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                <x-input-error :messages="$errors->get('container_env_file_input')" class="mt-2" />
            </div>
            <div>
                <label for="container_build_env_file_input" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Build-time') }}</label>
                <p class="mt-0.5 text-[11px] text-slate-500">{{ __('Available during build, hidden at runtime. Use for private package tokens.') }}</p>
                <textarea id="container_build_env_file_input" wire:model="container_build_env_file_input" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="NPM_TOKEN=ghp_xxx&#10;COMPOSER_AUTH={...}"></textarea>
                <x-input-error :messages="$errors->get('container_build_env_file_input')" class="mt-2" />
            </div>
        </div>
    </div>

    @php
        $attachedDatabases = \App\Models\CloudDatabase::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id))
            ->orderBy('name')
            ->get();
        $attachedDatabaseIds = $attachedDatabases->pluck('id')->all();
        $availableDatabases = \App\Models\CloudDatabase::query()
            ->where('organization_id', $site->organization_id)
            ->where('status', \App\Models\CloudDatabase::STATUS_ACTIVE)
            ->whereNotIn('id', $attachedDatabaseIds)
            ->orderBy('name')
            ->get();
        $dbEngineLabel = fn (string $engine): string => match ($engine) {
            \App\Models\CloudDatabase::ENGINE_POSTGRES => 'Postgres',
            \App\Models\CloudDatabase::ENGINE_MYSQL => 'MySQL',
            \App\Models\CloudDatabase::ENGINE_REDIS => 'Redis',
            default => ucfirst($engine),
        };
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Managed databases') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Attach a managed database and dply injects its connection env vars (DB_* / REDIS_*) and redeploys. Detaching strips exactly those keys.') }}</p>
        </div>

        @if ($attachedDatabases->isNotEmpty())
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($attachedDatabases as $database)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <span class="font-medium text-slate-900 break-all">{{ $database->name }}</span>
                            <span class="ml-2 text-xs text-slate-500">{{ $dbEngineLabel($database->engine) }} {{ $database->version }} · {{ ucfirst((string) $database->size) }} · {{ $database->region }}</span>
                        </div>
                        <button type="button"
                            wire:click="detachContainerDatabase('{{ $database->id }}')"
                            wire:confirm="{{ __('Detach :name from this app? Its connection env vars will be removed and the app redeployed.', ['name' => $database->name]) }}"
                            class="text-xs font-medium text-rose-700 hover:text-rose-900">
                            {{ __('Detach') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-xs text-slate-500">{{ __('No managed databases attached yet.') }}</p>
        @endif

        @if ($availableDatabases->isNotEmpty())
            <div class="flex flex-col gap-2 sm:flex-row">
                <select wire:model="container_database_attach_id" class="block w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">{{ __('Select a database…') }}</option>
                    @foreach ($availableDatabases as $database)
                        <option value="{{ $database->id }}">{{ $database->name }} — {{ $dbEngineLabel($database->engine) }} {{ $database->version }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="attachContainerDatabase" wire:loading.attr="disabled" wire:target="attachContainerDatabase" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="attachContainerDatabase">{{ __('Attach database') }}</span>
                    <span wire:loading wire:target="attachContainerDatabase">{{ __('Queueing…') }}</span>
                </button>
            </div>
        @else
            <p class="text-xs text-slate-400">{{ __('No more active databases available to attach.') }}
                <a href="{{ route('cloud.databases.create') }}" wire:navigate class="font-medium text-sky-700 hover:underline">{{ __('Create one') }} →</a>
            </p>
        @endif
    </div>

    @php
        $supportsWorkers = $this->containerSupportsWorkers();
        $siteWorkers = \App\Models\CloudWorker::query()
            ->where('site_id', $site->id)
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();
        $scheduler = $siteWorkers->firstWhere('type', \App\Models\CloudWorker::TYPE_SCHEDULER);
        $queueWorkers = $siteWorkers->where('type', \App\Models\CloudWorker::TYPE_WORKER);
        $workerStatusClass = fn (string $status): string => match ($status) {
            \App\Models\CloudWorker::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-800',
            \App\Models\CloudWorker::STATUS_PROVISIONING => 'bg-sky-100 text-sky-800',
            \App\Models\CloudWorker::STATUS_FAILED => 'bg-rose-100 text-rose-800',
            default => 'bg-slate-100 text-slate-700',
        };
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3" wire:key="workers-section">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Workers & scheduler') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Background processes that run alongside the web service — queue workers and the Laravel scheduler. They run the same code as the web process.') }}</p>
        </div>

        @if (! $supportsWorkers)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p class="font-semibold">{{ __('Not available on AWS App Runner') }}</p>
                <p class="mt-1">{{ __('App Runner services are HTTP-request-driven only — they cannot run background processes. Use a DigitalOcean App Platform site for queue workers and the scheduler.') }}</p>
            </div>
        @else
            @if ($siteWorkers->isNotEmpty())
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($siteWorkers as $worker)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="worker-{{ $worker->id }}">
                            <div class="min-w-0">
                                <span class="font-medium text-slate-900 break-all">{{ $worker->name }}</span>
                                <span class="ml-2 inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-600">{{ $worker->type }}</span>
                                <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $workerStatusClass($worker->status) }}">{{ $worker->status }}</span>
                                <p class="mt-0.5 font-mono text-[11px] text-slate-500 break-all">{{ $worker->effectiveCommand() }} · ×{{ $worker->effectiveInstanceCount() }} · {{ ucfirst((string) $worker->size) }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @unless ($worker->isScheduler())
                                    <button type="button" wire:click="scaleContainerWorker('{{ $worker->id }}', {{ $worker->effectiveInstanceCount() + 1 }})" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50">+</button>
                                    <button type="button" wire:click="scaleContainerWorker('{{ $worker->id }}', {{ max(1, $worker->effectiveInstanceCount() - 1) }})" class="rounded-md border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50">−</button>
                                @endunless
                                <button type="button"
                                    wire:click="removeContainerWorker('{{ $worker->id }}')"
                                    wire:confirm="{{ __('Remove :name? The backend will drop the component and redeploy.', ['name' => $worker->name]) }}"
                                    class="text-xs font-medium text-rose-700 hover:text-rose-900">
                                    {{ __('Remove') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-xs text-slate-500">{{ __('No workers configured yet.') }}</p>
            @endif

            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Add a queue worker') }}</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input type="text" wire:model="container_worker_command_input" class="block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="php artisan queue:work" />
                    <select wire:model="container_worker_size_input" class="rounded-md border-slate-300 text-xs shadow-sm">
                        <option value="small">{{ __('Small') }}</option>
                        <option value="medium">{{ __('Medium') }}</option>
                        <option value="large">{{ __('Large') }}</option>
                        <option value="xlarge">{{ __('XLarge') }}</option>
                    </select>
                    <input type="number" min="1" wire:model="container_worker_count_input" class="w-20 rounded-md border-slate-300 text-xs shadow-sm" />
                    <button type="button" wire:click="addContainerWorker" wire:loading.attr="disabled" wire:target="addContainerWorker" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="addContainerWorker">{{ __('Add worker') }}</span>
                        <span wire:loading wire:target="addContainerWorker">{{ __('Queueing…') }}</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Laravel scheduler') }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        @if ($scheduler)
                            {{ __('Enabled — running `php artisan schedule:work` on one instance.') }}
                        @else
                            {{ __('Disabled. Enable to run scheduled tasks (App Platform has no native cron).') }}
                        @endif
                    </p>
                </div>
                @if ($scheduler)
                    <button type="button" wire:click="disableContainerScheduler" wire:confirm="{{ __('Disable the scheduler? Scheduled tasks will stop running.') }}" class="text-xs font-medium text-rose-700 hover:text-rose-900">{{ __('Disable scheduler') }}</button>
                @else
                    <button type="button" wire:click="enableContainerScheduler" wire:loading.attr="disabled" wire:target="enableContainerScheduler" class="inline-flex items-center justify-center rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-sky-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="enableContainerScheduler">{{ __('Enable scheduler') }}</span>
                        <span wire:loading wire:target="enableContainerScheduler">{{ __('Queueing…') }}</span>
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- Scaling & health — autoscaling rules + HTTP health checks --}}
    @php
        $supportsAutoscaling = $this->containerSupportsAutoscaling();
        $isAppRunner = $site->container_backend === 'aws_app_runner';
        $autoscalingNote = $containerMeta['autoscaling']['backend_note'] ?? null;
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-4" wire:key="scaling-section">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Scaling & health') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('CPU-target autoscaling and an HTTP health check, pushed into the backend deployment spec.') }}</p>
        </div>

        @if (! $supportsAutoscaling)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p class="font-semibold">{{ __('Not available on this backend') }}</p>
                <p class="mt-1">{{ __('This site\'s backend does not support autoscaling or health-check configuration.') }}</p>
            </div>
        @else
            {{-- Autoscaling --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-3">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="container_autoscaling_enabled" class="rounded border-slate-300 text-sky-700 shadow-sm" />
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('Autoscaling') }}</span>
                </label>
                <p class="text-[11px] text-slate-500">{{ __('When on, the backend scales the web service between the min and max instance counts to hold the target CPU. The fixed instance count is superseded.') }}</p>

                @if ($container_autoscaling_enabled)
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="container_autoscaling_min" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Min instances') }}</label>
                            <input id="container_autoscaling_min" type="number" min="1" wire:model="container_autoscaling_min" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_autoscaling_max" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Max instances') }}</label>
                            <input id="container_autoscaling_max" type="number" min="1" wire:model="container_autoscaling_max" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_autoscaling_cpu" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Target CPU %') }}</label>
                            <input id="container_autoscaling_cpu" type="number" min="1" max="100" wire:model="container_autoscaling_cpu" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                    </div>
                    <div class="rounded-md bg-sky-50 p-2 text-[11px] text-sky-900">
                        {{ __('The manual instance count (currently :n) is superseded while autoscaling is on.', ['n' => (int) ($containerMeta['instance_count'] ?? 1)]) }}
                    </div>
                @else
                    <p class="text-[11px] text-slate-500">{{ __('Autoscaling is off — the site runs a fixed :n instance(s).', ['n' => (int) ($containerMeta['instance_count'] ?? 1)]) }}</p>
                @endif

                @if ($isAppRunner)
                    <p class="rounded-md bg-amber-50 p-2 text-[11px] text-amber-900">{{ __('On AWS App Runner, autoscaling uses a concurrency-driven AutoScalingConfiguration (min/max size). The CPU target is recorded as intent only.') }}</p>
                    @if ($autoscalingNote)
                        <p class="rounded-md bg-slate-100 p-2 text-[11px] text-slate-700 break-words">{{ $autoscalingNote }}</p>
                    @endif
                @endif

                <button type="button" wire:click="saveContainerAutoscaling" wire:loading.attr="disabled" wire:target="saveContainerAutoscaling" class="inline-flex items-center justify-center rounded-xl bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-sky-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveContainerAutoscaling">{{ __('Save autoscaling') }}</span>
                    <span wire:loading wire:target="saveContainerAutoscaling">{{ __('Saving…') }}</span>
                </button>
            </div>

            {{-- Health check --}}
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-3">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="container_health_check_enabled" class="rounded border-slate-300 text-sky-700 shadow-sm" />
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-600">{{ __('HTTP health check') }}</span>
                </label>
                <p class="text-[11px] text-slate-500">{{ __('The backend probes this path and only routes traffic to instances that respond healthy.') }}</p>

                @if ($container_health_check_enabled)
                    <div>
                        <label for="container_health_check_path" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('HTTP path') }}</label>
                        <input id="container_health_check_path" type="text" wire:model="container_health_check_path" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" placeholder="/health" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <div>
                            <label for="container_health_check_initial_delay" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Initial delay (s)') }}</label>
                            <input id="container_health_check_initial_delay" type="number" min="0" wire:model="container_health_check_initial_delay" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_health_check_period" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Period (s)') }}</label>
                            <input id="container_health_check_period" type="number" min="1" wire:model="container_health_check_period" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_health_check_timeout" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Timeout (s)') }}</label>
                            <input id="container_health_check_timeout" type="number" min="1" wire:model="container_health_check_timeout" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_health_check_success" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Success threshold') }}</label>
                            <input id="container_health_check_success" type="number" min="1" wire:model="container_health_check_success" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                        <div>
                            <label for="container_health_check_failure" class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Failure threshold') }}</label>
                            <input id="container_health_check_failure" type="number" min="1" wire:model="container_health_check_failure" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" />
                        </div>
                    </div>
                @else
                    <p class="text-[11px] text-slate-500">{{ __('Health check is off — the backend uses its default readiness behaviour.') }}</p>
                @endif

                @if ($isAppRunner)
                    <p class="rounded-md bg-amber-50 p-2 text-[11px] text-amber-900">{{ __('On AWS App Runner the health check maps to the service HealthCheckConfiguration. App Runner has no "initial delay" — that field is ignored.') }}</p>
                @endif

                <button type="button" wire:click="saveContainerHealthCheck" wire:loading.attr="disabled" wire:target="saveContainerHealthCheck" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveContainerHealthCheck">{{ __('Save health check') }}</span>
                    <span wire:loading wire:target="saveContainerHealthCheck">{{ __('Saving…') }}</span>
                </button>
            </div>
        @endif
    </div>

    @if ($isSourceMode && empty($containerMeta['preview_parent_site_id']))
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('GitHub webhook') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Paste this URL + the site\'s webhook secret into your repository\'s GitHub webhook settings. dply will spawn previews on PR open / sync, tear them down on PR close, and redeploy on push to the source branch.') }}</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-[auto_1fr] sm:items-center">
                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Payload URL') }}</span>
                <input type="text" readonly value="{{ $site->cloudGithubHookUrl() }}" class="block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm" onclick="this.select()" />
                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Content type') }}</span>
                <code class="rounded bg-slate-100 px-2 py-1 text-xs">application/json</code>
                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Secret') }}</span>
                <a href="{{ route('sites.show', ['server' => $site->server, 'site' => $site, 'section' => 'notifications']) }}" wire:navigate class="text-xs text-sky-700 hover:underline">{{ __('Open Settings → Webhooks to reveal & rotate the secret') }} →</a>
                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('Events') }}</span>
                <span class="text-xs text-slate-700">{{ __('"Pushes" + "Pull requests" (or "Send me everything").') }}</span>
            </div>
        </div>

        @php
            $previews = \App\Actions\Cloud\CreateCloudPreviewSite::listForParent($site);
        @endphp
        @if ($previews->isNotEmpty())
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Preview deployments') }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('One preview per branch — typically driven by your CI on PR open / sync / close.') }}</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-800">{{ $previews->count() }}</span>
                </div>
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($previews as $preview)
                        @php
                            $previewMeta = is_array($preview->meta['container'] ?? null) ? $preview->meta['container'] : [];
                            $previewBranch = $previewMeta['preview_branch'] ?? '—';
                            $previewPrNumber = $previewMeta['preview_pr_number'] ?? null;
                            $previewLiveUrl = $preview->containerLiveUrl();
                            $previewStatusClass = match ($preview->status) {
                                \App\Models\Site::STATUS_CONTAINER_ACTIVE => 'bg-emerald-100 text-emerald-800',
                                \App\Models\Site::STATUS_CONTAINER_PROVISIONING => 'bg-sky-100 text-sky-800',
                                \App\Models\Site::STATUS_CONTAINER_FAILED => 'bg-rose-100 text-rose-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-xs">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-slate-900">
                                    @if ($previewPrNumber)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">PR #{{ $previewPrNumber }}</span>
                                    @endif
                                    {{ $previewBranch }}
                                </p>
                                @if ($previewLiveUrl)
                                    <a href="{{ $previewLiveUrl }}" target="_blank" rel="noopener" class="break-all text-sky-700 hover:underline">{{ $previewLiveUrl }}</a>
                                @else
                                    <span class="text-slate-500">{{ __('No live URL yet') }}</span>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $previewStatusClass }}">
                                    {{ str_replace('_', ' ', (string) $preview->status) }}
                                </span>
                                <button type="button"
                                    wire:click="tearDownContainerPreview('{{ $preview->id }}')"
                                    wire:confirm="{{ __('Tear down preview for branch :branch?', ['branch' => $previewBranch]) }}"
                                    class="text-[11px] font-medium text-rose-700 hover:text-rose-900">
                                    {{ __('Tear down') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    {{-- Observability — CPU/memory/restart metrics + runtime logs --}}
    @php
        // Convert a list of {t, v} points into the {at, min, avg, max}
        // shape the shared x-metrics-line-chart component expects.
        $toChartSeries = function (mixed $points): array {
            if (! is_array($points)) {
                return [];
            }
            $out = [];
            foreach ($points as $p) {
                if (! is_array($p) || ! isset($p['t'], $p['v'])) {
                    continue;
                }
                $v = (float) $p['v'];
                $out[] = ['at' => (int) $p['t'], 'min' => $v, 'avg' => $v, 'max' => $v];
            }

            return $out;
        };
        $metricsWindows = \App\Services\Cloud\ResolvesMetricWindows::metricWindows();
        $metricsResult = is_array($container_metrics_result) ? $container_metrics_result : null;
        $metricsSeries = is_array($metricsResult['series'] ?? null) ? $metricsResult['series'] : [];
        $metricsAvailable = (bool) ($metricsResult['available'] ?? false);
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-4" wire:key="observability-section">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Observability') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('CPU, memory and restart metrics plus runtime logs, pulled live from the backend (cached ~60s).') }}</p>
            </div>
            <div class="flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-0.5">
                @foreach ($metricsWindows as $window)
                    <button type="button"
                            wire:click="setContainerMetricsWindow('{{ $window }}')"
                            class="rounded-md px-2.5 py-1 text-[11px] font-semibold {{ $container_metrics_window === $window ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                        {{ $window }}
                    </button>
                @endforeach
            </div>
        </div>

        @if ($metricsResult === null)
            <div class="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-4 text-center">
                <p class="text-xs text-slate-500">{{ __('Metrics have not been loaded yet.') }}</p>
                <button type="button" wire:click="refreshContainerMetrics" wire:loading.attr="disabled" wire:target="refreshContainerMetrics" class="mt-2 inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="refreshContainerMetrics">{{ __('Load metrics') }}</span>
                    <span wire:loading wire:target="refreshContainerMetrics">{{ __('Loading…') }}</span>
                </button>
            </div>
        @elseif (! $metricsAvailable)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
                <p class="font-semibold">{{ __('Metrics unavailable for this backend.') }}</p>
                @if (! empty($metricsResult['note']))
                    <p class="mt-1">{{ $metricsResult['note'] }}</p>
                @endif
                @if (! empty($metricsResult['url']))
                    <a href="{{ $metricsResult['url'] }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 font-semibold text-amber-900 underline">
                        {{ __('View in CloudWatch') }} →
                    </a>
                @endif
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <p class="text-xs font-semibold text-slate-700">{{ __('CPU') }} <span class="text-slate-400">%</span></p>
                    <div class="mt-2">
                        <x-metrics-line-chart
                            :series="$toChartSeries($metricsSeries['cpu'] ?? [])"
                            :y-min="0"
                            :y-max="100"
                            color-class="text-sky-600"
                            format="percent"
                            height-class="h-28"
                        />
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3">
                    <p class="text-xs font-semibold text-slate-700">{{ __('Memory') }} <span class="text-slate-400">%</span></p>
                    <div class="mt-2">
                        <x-metrics-line-chart
                            :series="$toChartSeries($metricsSeries['memory'] ?? [])"
                            :y-min="0"
                            :y-max="100"
                            color-class="text-amber-600"
                            format="percent"
                            height-class="h-28"
                        />
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-3 {{ isset($metricsSeries['requests']) ? '' : 'sm:col-span-2' }}">
                    <p class="text-xs font-semibold text-slate-700">{{ isset($metricsSeries['requests']) ? __('Requests') : __('Restarts') }}</p>
                    <div class="mt-2">
                        <x-metrics-line-chart
                            :series="$toChartSeries($metricsSeries['requests'] ?? ($metricsSeries['restarts'] ?? []))"
                            :y-min="0"
                            color-class="text-rose-600"
                            format="load"
                            height-class="h-28"
                        />
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-end">
                <button type="button" wire:click="refreshContainerMetrics" wire:loading.attr="disabled" wire:target="refreshContainerMetrics" class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="refreshContainerMetrics">{{ __('Refresh metrics') }}</span>
                    <span wire:loading wire:target="refreshContainerMetrics">{{ __('Refreshing…') }}</span>
                </button>
            </div>
        @endif

        {{-- Runtime (RUN) logs viewer --}}
        <div class="rounded-xl border border-slate-200 bg-white p-3" @if ($container_log_tail_active) wire:poll.2s="pollContainerRuntimeLogs" @endif>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold text-slate-700">{{ __('Runtime logs') }}</p>
                    <p class="mt-0.5 text-[11px] text-slate-500">{{ __('Last 200 lines on demand, or live-tail new ones as they arrive.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @php $logComponentChoices = $this->containerLogTailComponentChoices(); @endphp
                    @if (count($logComponentChoices) > 1)
                        <select wire:model.live="container_log_tail_component" class="rounded-xl border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-800 shadow-sm">
                            @foreach ($logComponentChoices as $choice)
                                <option value="{{ $choice['value'] }}">{{ $choice['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($container_log_tail_active)
                        <button type="button" wire:click="toggleContainerLogTail" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-100">
                            <span class="relative inline-flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-60"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-rose-500"></span>
                            </span>
                            {{ __('Stop tail') }}
                        </button>
                    @else
                        <button type="button" wire:click="toggleContainerLogTail" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 shadow-sm hover:bg-emerald-100">
                            <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ __('Live tail') }}
                        </button>
                    @endif
                    <button type="button" wire:click="fetchContainerRuntimeLogs" wire:loading.attr="disabled" wire:target="fetchContainerRuntimeLogs" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                        <span wire:loading.remove wire:target="fetchContainerRuntimeLogs">{{ is_array($container_runtime_logs_result) ? __('Refresh') : __('Fetch') }}</span>
                        <span wire:loading wire:target="fetchContainerRuntimeLogs">{{ __('Fetching…') }}</span>
                    </button>
                </div>
            </div>

            {{-- Live tail panel — only rendered while tailing or when buffer has lines --}}
            @if ($container_log_tail_active || ! empty($container_log_tail_lines))
                <div class="mt-3">
                    <pre class="max-h-72 overflow-auto rounded-lg border border-emerald-200 bg-slate-900 p-3 font-mono text-[11px] leading-5 text-emerald-50">{{ $container_log_tail_lines !== [] ? implode("\n", $container_log_tail_lines) : __('Waiting for log lines…') }}</pre>
                    @if ($container_log_tail_active)
                        <p class="mt-1 inline-flex items-center gap-1.5 text-[11px] text-emerald-700">
                            <span class="relative inline-flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            </span>
                            {{ __('Tailing — refreshing every 2s.') }}
                        </p>
                    @endif
                </div>
            @endif
            @if (is_array($container_runtime_logs_result))
                @php
                    $runtimeLines = is_array($container_runtime_logs_result['lines'] ?? null) ? $container_runtime_logs_result['lines'] : [];
                    $runtimeAvailable = (bool) ($container_runtime_logs_result['available'] ?? false);
                @endphp
                <div class="mt-3">
                    @if ($runtimeLines !== [])
                        <pre class="max-h-72 overflow-auto rounded-lg border border-slate-200 bg-slate-900 p-3 font-mono text-[11px] leading-5 text-slate-100">{{ implode("\n", array_map('strval', $runtimeLines)) }}</pre>
                    @endif
                    @if (! $runtimeAvailable && ! empty($container_runtime_logs_result['note']))
                        <p class="mt-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">{{ $container_runtime_logs_result['note'] }}</p>
                    @elseif ($runtimeLines === [] && ! empty($container_runtime_logs_result['note']))
                        <p class="mt-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ $container_runtime_logs_result['note'] }}</p>
                    @elseif ($runtimeLines === [] && $runtimeAvailable)
                        <p class="mt-2 text-xs text-slate-500">{{ __('No runtime log lines returned.') }}</p>
                    @endif
                    @if (! empty($container_runtime_logs_result['url']))
                        <a href="{{ $container_runtime_logs_result['url'] }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 break-all text-xs font-medium text-sky-700 hover:underline">
                            {{ __('Open log archive / console') }} →
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Latest deployment logs') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Fetches the most recent build / deploy log link from the backend on demand.') }}</p>
            </div>
            <button type="button" wire:click="fetchContainerLogs" wire:loading.attr="disabled" wire:target="fetchContainerLogs" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                <span wire:loading.remove wire:target="fetchContainerLogs">{{ __('Fetch logs') }}</span>
                <span wire:loading wire:target="fetchContainerLogs">{{ __('Fetching…') }}</span>
            </button>
        </div>
        @if (is_array($container_logs_result))
            <div class="mt-3">
                @if (! empty($container_logs_result['url']))
                    <a href="{{ $container_logs_result['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-sky-700 hover:underline">
                        {{ $container_logs_result['url'] }} →
                    </a>
                @elseif (! empty($container_logs_result['content']))
                    <pre class="max-h-64 overflow-auto rounded-lg border border-slate-200 bg-slate-900 p-3 font-mono text-[11px] leading-5 text-slate-100">{{ $container_logs_result['content'] }}</pre>
                @elseif (! empty($container_logs_result['message']))
                    <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{{ $container_logs_result['message'] }}</p>
                @else
                    <p class="text-xs text-slate-500">{{ __('No logs returned by backend.') }}</p>
                @endif
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Recent deployments') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Live history pulled from the backend on demand.') }}</p>
            </div>
            <button type="button" wire:click="fetchContainerDeployments" wire:loading.attr="disabled" wire:target="fetchContainerDeployments" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50">
                <span wire:loading.remove wire:target="fetchContainerDeployments">{{ __('Fetch deployments') }}</span>
                <span wire:loading wire:target="fetchContainerDeployments">{{ __('Fetching…') }}</span>
            </button>
        </div>
        @if (is_array($container_deployments_result))
            @if ($container_deployments_result === [])
                <p class="mt-3 text-xs text-slate-500">{{ __('No deployments returned by backend.') }}</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                    @foreach ($container_deployments_result as $deployment)
                        @php
                            $depPhase = (string) ($deployment['phase'] ?? 'UNKNOWN');
                            $depPhaseClass = match ($depPhase) {
                                'ACTIVE' => 'bg-emerald-100 text-emerald-800',
                                'BUILDING', 'DEPLOYING' => 'bg-sky-100 text-sky-800',
                                'ERROR', 'FAILED' => 'bg-rose-100 text-rose-800',
                                'SUPERSEDED' => 'bg-slate-100 text-slate-700',
                                default => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2">
                            <div class="min-w-0 flex-1">
                                @php $depId = (string) ($deployment['id'] ?? ''); @endphp
                                @if ($depId !== '' && $site->container_backend === 'digitalocean_app_platform')
                                    <a href="{{ route('sites.cloud.deploys.show', ['server' => $site->server, 'site' => $site, 'deploy' => $depId]) }}" wire:navigate class="font-mono text-[11px] text-sky-700 hover:underline">{{ substr($depId, 0, 12) }}</a>
                                @else
                                    <p class="font-mono text-[11px] text-slate-900">{{ substr($depId ?: '—', 0, 12) }}</p>
                                @endif
                                @if ($deployment['started_at'] ?? null)
                                    <p class="mt-0.5 text-[10px] text-slate-500">{{ __('Started :at', ['at' => $deployment['started_at']]) }}{{ ($deployment['cause'] ?? null) ? ' · '.$deployment['cause'] : '' }}</p>
                                @endif
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] {{ $depPhaseClass }}">
                                {{ str_replace('_', ' ', $depPhase) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </div>

    @php
        $manualDeployTasks = \App\Models\CloudDeployTask::query()
            ->where('site_id', $site->id)
            ->where('trigger', \App\Models\CloudDeployTask::TRIGGER_MANUAL)
            ->orderBy('created_at')
            ->get();
    @endphp
    @if ($manualDeployTasks->isNotEmpty())
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Operations') }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Manual deploy tasks for this app. Trigger from DigitalOcean\'s control panel — public API support for running individual MANUAL jobs is pending.') }}</p>
                </div>
            </div>
            <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($manualDeployTasks as $task)
                    @php
                        $latestRun = \App\Models\CloudDeployTaskRun::query()
                            ->where('cloud_deploy_task_id', $task->id)
                            ->orderByDesc('finished_at')
                            ->orderByDesc('created_at')
                            ->first();
                        $consolePath = $site->container_backend_id
                            ? 'https://cloud.digitalocean.com/apps/'.$site->container_backend_id
                            : null;
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-slate-900">{{ $task->name }}</p>
                            <p class="mt-0.5 truncate font-mono text-[10px] text-slate-500">{{ $task->command }}</p>
                            @if ($latestRun)
                                @php
                                    $statusClass = match ($latestRun->status) {
                                        'succeeded' => 'text-emerald-700',
                                        'failed' => 'text-rose-700',
                                        'running' => 'text-sky-700',
                                        default => 'text-slate-500',
                                    };
                                @endphp
                                <p class="mt-1 text-[10px] {{ $statusClass }}">
                                    {{ __('Last run:') }} {{ $latestRun->status }}
                                    @if ($latestRun->exit_code !== null) · {{ __('exit') }} {{ $latestRun->exit_code }} @endif
                                    @if ($latestRun->finished_at) · {{ $latestRun->finished_at->diffForHumans() }} @endif
                                </p>
                            @endif
                        </div>
                        @if ($consolePath)
                            <a href="{{ $consolePath }}" target="_blank" rel="noopener" class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" aria-hidden="true" />
                                {{ __('Run in DO') }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $activity = \App\Support\Cloud\ContainerActivityTimeline::for($site);
    @endphp
    @if ($activity !== [])
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Recent activity') }}</p>
            <ol class="mt-3 space-y-2">
                @foreach (array_slice($activity, 0, 8) as $event)
                    <li class="flex items-start gap-3 text-xs">
                        <span class="mt-0.5 inline-flex size-2 shrink-0 rounded-full
                            {{ $event['kind'] === 'error' || $event['kind'] === 'poll_error' ? 'bg-rose-500' : '' }}
                            {{ $event['kind'] === 'provisioned' ? 'bg-emerald-500' : '' }}
                            {{ $event['kind'] === 'deploy' ? 'bg-sky-500' : '' }}
                            {{ $event['kind'] === 'domain_attached' ? 'bg-indigo-500' : '' }}
                            {{ $event['kind'] === 'teardown' ? 'bg-slate-400' : '' }}
                            {{ $event['kind'] === 'poll' ? 'bg-slate-300' : '' }}
                        "></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-slate-900">{{ __($event['label']) }}</p>
                            @if ($event['detail'])
                                <p class="mt-0.5 break-all text-slate-600">{{ $event['detail'] }}</p>
                            @endif
                        </div>
                        @if ($event['at'])
                            <span class="shrink-0 font-mono text-[10px] text-slate-500" title="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans(null, true) }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @php
        $attachedDomains = is_array($containerMeta['domains'] ?? null) ? $containerMeta['domains'] : [];
    @endphp
    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Custom domains') }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ __('Point your own hostnames at the backend\'s default ingress. Validation records (if any) appear after the attach completes.') }}</p>
        </div>

        @if ($attachedDomains !== [])
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($attachedDomains as $hostname => $info)
                    <li class="px-3 py-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-slate-900 break-all">{{ $hostname }}</span>
                            <button type="button" wire:click="detachContainerDomain('{{ $hostname }}')" wire:confirm="{{ __('Remove :host from this app?', ['host' => $hostname]) }}" class="text-xs font-medium text-rose-700 hover:text-rose-900">{{ __('Remove') }}</button>
                        </div>
                        @if (! empty($info['validation_records']))
                            <div class="mt-2 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-700">
                                <p class="font-semibold uppercase tracking-[0.14em] text-slate-500">{{ __('DNS validation records') }}</p>
                                @foreach ($info['validation_records'] as $rec)
                                    <p class="font-mono break-all">{{ $rec['type'] }} <span class="text-slate-500">→</span> {{ $rec['name'] }} <span class="text-slate-500">⇒</span> {{ $rec['value'] }}</p>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-xs text-slate-500">{{ __('No custom domains attached yet — the app is reachable at its default backend URL.') }}</p>
        @endif

        <div class="flex flex-col gap-2 sm:flex-row">
            <input type="text" wire:model="container_domain_input" class="block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="api.example.com" />
            <button type="button" wire:click="attachContainerDomain" wire:loading.attr="disabled" wire:target="attachContainerDomain" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="attachContainerDomain">{{ __('Attach domain') }}</span>
                <span wire:loading wire:target="attachContainerDomain">{{ __('Queueing…') }}</span>
            </button>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Deploy webhook') }}</p>
        <p class="text-xs text-slate-500">{{ __('POST to this URL from your CI to redeploy. Optional JSON body { "image": "ghcr.io/me/api:v2" } bumps the tag.') }}</p>
        <input type="text" readonly value="{{ $site->cloudRedeployHookUrl() }}" class="block w-full select-all rounded-md border-slate-300 bg-slate-50 font-mono text-xs text-slate-800 shadow-sm" onclick="this.select()" />
    </div>

    <div class="flex justify-end border-t border-slate-200 pt-4">
        <button type="button" wire:click="tearDownContainer" wire:confirm="{{ __('Permanently delete the container deployment? The backend resource will be torn down.') }}" class="text-sm font-medium text-rose-700 hover:text-rose-900">
            {{ __('Tear down container') }}
        </button>
    </div>
</section>
