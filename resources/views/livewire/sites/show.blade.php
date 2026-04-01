@php
    $testingHostname = $site->testingHostname();
    $testingHostnameMeta = is_array($site->meta['testing_hostname'] ?? null) ? $site->meta['testing_hostname'] : [];
    $provisioningMeta = $site->provisioningMeta();
    $provisioningState = $site->provisioningState() ?? 'queued';
    $provisioningError = $site->provisioningError();
    $targetUrl = $testingHostname ? 'http://'.$testingHostname : ($site->visitUrl() ?? null);
    $readyForWorkspace = $site->isReadyForTraffic();
    $hostChecks = collect($provisioningMeta['host_checks'] ?? [])
        ->filter(fn ($check) => is_array($check) && is_string($check['hostname'] ?? null))
        ->values();
    $provisioningLog = collect($site->provisioningLog())->reverse()->values();
    $provisioningConsoleOutput = $provisioningLog->map(function (array $entry): string {
        $lines = [];
        $level = strtoupper((string) ($entry['level'] ?? 'info'));
        $step = trim(str_replace('_', ' ', (string) ($entry['step'] ?? '')));
        $timestamp = ! empty($entry['at'])
            ? \Illuminate\Support\Carbon::parse($entry['at'])->format('M j, H:i:s')
            : null;

        $header = trim(collect([$level, $step !== '' ? '['.$step.']' : null, $timestamp])->filter()->implode(' '));
        if ($header !== '') {
            $lines[] = $header;
        }

        $lines[] = (string) ($entry['message'] ?? __('Provisioning event recorded.'));

        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        foreach ($context as $key => $value) {
            $formattedValue = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string) $value;

            $lines[] = '  '.strtoupper(str_replace('_', ' ', (string) $key)).': '.$formattedValue;
        }

        return implode("\n", $lines);
    })->implode("\n\n");
    $statusSteps = [
        'queued' => __('Queued'),
        'provisioning_testing_hostname' => __('Assigning testing hostname'),
        'writing_site_config' => __('Writing site config'),
        'waiting_for_http' => __('Checking reachability'),
        'ready' => __('Site available'),
        'failed' => __('Needs attention'),
    ];
    $stepKeys = array_keys($statusSteps);
    $currentStepIndex = array_search($provisioningState, $stepKeys, true);
    $currentStepIndex = $currentStepIndex === false ? 0 : $currentStepIndex;
    $sidebarItems = [
        ['id' => 'general', 'label' => __('General'), 'icon' => 'heroicon-o-rectangle-stack'],
        ['id' => 'deployment-log', 'label' => __('Queue'), 'icon' => 'heroicon-o-code-bracket'],
        ['id' => 'ssl', 'label' => __('SSL'), 'icon' => 'heroicon-o-lock-closed'],
        ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-share'],
        ['id' => 'repository', 'label' => __('Repository'), 'icon' => 'heroicon-o-code-bracket-square'],
        ['id' => 'redirects', 'label' => __('Redirects'), 'icon' => 'heroicon-o-arrows-right-left'],
        ['id' => 'deploy-settings', 'label' => __('Settings'), 'icon' => 'heroicon-o-cog-6-tooth'],
        ['id' => 'logs', 'label' => __('Logs'), 'icon' => 'heroicon-o-clipboard-document-list'],
        ['id' => 'manage', 'label' => __('Manage'), 'icon' => 'heroicon-o-archive-box'],
    ];
    if ($site->type?->value === 'php') {
        array_splice($sidebarItems, 5, 0, [[
            'id' => 'laravel',
            'label' => __('Laravel'),
            'icon' => 'heroicon-o-cube-transparent',
        ]]);
    }
    if ($site->visitUrl()) {
        $sidebarItems[] = [
            'id' => 'view',
            'label' => __('View'),
            'icon' => 'heroicon-o-arrow-top-right-on-square',
            'href' => $site->visitUrl(),
            'external' => true,
        ];
    }
@endphp

<div>
    @if ($site->server_id)
        <div
            id="dply-site-provisioning-context"
            data-server-id="{{ $site->server_id }}"
            data-site-id="{{ $site->id }}"
            data-subscribe="1"
            class="hidden"
            aria-hidden="true"
        ></div>
    @endif
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center flex-wrap gap-2">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $site->name }}</h2>
                <p class="text-sm text-slate-500">
                    {{ $server->name }} · {{ $site->type->label() }}
                    @if ($site->workspace)
                        · {{ __('Project:') }}
                        <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="font-medium text-slate-700 hover:text-slate-900">
                            {{ $site->workspace->name }}
                        </a>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-4">
                @if ($readyForWorkspace && $site->workspace)
                    <a href="{{ route('projects.resources', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project') }}
                    </a>
                    <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Project delivery') }}
                    </a>
                @endif
                @if ($readyForWorkspace)
                    <a href="{{ route('sites.insights', [$server, $site]) }}" wire:navigate class="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 text-sm font-medium">
                        {{ __('Insights') }}
                        @if ($openSiteInsightsCount > 0)
                            <span class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[11px] font-semibold leading-none text-white" title="{{ trans_choice(':count open finding|:count open findings', $openSiteInsightsCount, ['count' => $openSiteInsightsCount]) }}">{{ $openSiteInsightsCount }}</span>
                        @endif
                    </a>
                @endif
                <a href="{{ route('servers.show', $server) }}" class="text-slate-500 hover:text-slate-700 text-sm">← Server</a>
            </div>
        </div>
    </header>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($flash_success)
                <div class="p-4 rounded-md bg-green-50 text-green-800">{{ $flash_success }}</div>
            @endif
            @if ($flash_error)
                <div class="p-4 rounded-md bg-red-50 text-red-800">{{ $flash_error }}</div>
            @endif

            @if ($this->deployLockInfo)
                <div class="p-4 rounded-md bg-amber-50 text-amber-900 text-sm border border-amber-200" wire:poll.5s>
                    <strong>Deployment in progress</strong>
                    @if (! empty($this->deployLockInfo['deployment_id']))
                        <span class="text-amber-800">· run #{{ $this->deployLockInfo['deployment_id'] }}</span>
                    @endif
                    <p class="mt-1 text-amber-800">Queued deploys may appear as <span class="font-medium">skipped</span> until this run finishes.</p>
                    <button type="button" wire:click="openConfirmActionModal('releaseDeployLock', [], @js(__('Clear deploy lock')), @js(__('Force-clear the deploy lock? Only if no worker is actually deploying.')), @js(__('Clear lock')), true)" class="mt-2 text-sm text-amber-900 underline">Clear lock</button>
                </div>
            @endif

            @if (! $readyForWorkspace)
                <div class="mx-auto max-w-5xl space-y-6" wire:poll.5s="pollProvisioningStatus">
                    <div class="overflow-hidden rounded-[1.75rem] border {{ $provisioningState === 'failed' ? 'border-red-200 bg-red-50/40' : 'border-slate-200 bg-white' }} shadow-sm">
                        <div class="grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1.55fr)_20rem]">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.28em] {{ $provisioningState === 'failed' ? 'text-red-700/90' : 'text-slate-500' }}">
                                    {{ __('Site status') }}
                                </p>
                                <div class="mt-5 rounded-[1.5rem] border {{ $provisioningState === 'failed' ? 'border-red-200 bg-white/90' : 'border-slate-200 bg-slate-50/70' }} px-5 py-5 sm:px-6">
                                    <div class="flex items-start gap-4 sm:gap-5">
                                    <div class="mt-0.5 flex size-14 shrink-0 self-start items-center justify-center rounded-2xl {{ $provisioningState === 'failed' ? 'bg-red-100 text-red-700 ring-1 ring-red-200' : 'bg-blue-50 text-blue-700 ring-1 ring-blue-200' }}">
                                        @if ($provisioningState === 'failed')
                                            <x-heroicon-o-exclamation-triangle class="h-7 w-7" />
                                        @else
                                            <x-heroicon-o-arrow-path class="h-7 w-7" />
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-3xl font-semibold tracking-tight text-slate-950 sm:text-[1.9rem]">
                                            @if ($provisioningState === 'failed')
                                                {{ __('Provisioning failed') }}
                                            @else
                                                {{ __('Installing your site') }}
                                            @endif
                                        </h3>
                                        <p class="mt-2 inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $provisioningState === 'failed' ? 'bg-red-100 text-red-800 ring-1 ring-red-200' : 'bg-blue-100 text-blue-800 ring-1 ring-blue-200' }}">
                                            {{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}
                                        </p>
                                        <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-600">
                                            @if ($provisioningError)
                                                {{ __('Dply hit a server-level setup problem while finishing installation. Fix the underlying access issue, then retry from this page.') }}
                                            @else
                                                {{ __('Dply is writing the web server config, attaching your temporary testing URL, and checking which hostname becomes reachable first. As soon as either the testing URL or the real domain responds, the full site workspace will appear here.') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                </div>

                                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                                    @if ($targetUrl)
                                        <div class="rounded-2xl border border-slate-200 bg-white/85 px-4 py-4 shadow-sm">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Testing URL') }}</p>
                                            <p class="mt-3 break-all font-mono text-sm text-slate-900">{{ $targetUrl }}</p>
                                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ __('Use this first while the customer domain catches up.') }}</p>
                                        </div>
                                    @endif

                                    <div class="rounded-2xl border border-slate-200 bg-white/85 px-4 py-4 shadow-sm">
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Install summary') }}</p>
                                        <dl class="mt-3 space-y-3 text-sm">
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Primary domain') }}</dt>
                                                <dd class="max-w-[16rem] break-all text-right font-mono text-slate-900">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                                            </div>
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Web server') }}</dt>
                                                <dd class="font-medium capitalize text-slate-900">{{ $site->webserver() }}</dd>
                                            </div>
                                            <div class="flex items-start justify-between gap-3">
                                                <dt class="text-slate-500">{{ __('Current step') }}</dt>
                                                <dd class="font-medium text-slate-900">{{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>

                                @if ($provisioningError)
                                    <div class="mt-6 rounded-2xl border border-red-200 bg-white/90 p-4 shadow-sm">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-red-700">{{ __('Latest error') }}</p>
                                                <p class="mt-3 break-all font-mono text-xs leading-6 text-slate-700">{{ $provisioningError }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-col justify-between gap-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/70 p-5 shadow-sm">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ __('Current step') }}</p>
                                    <p class="mt-3 text-xl font-semibold tracking-tight text-slate-950">{{ $statusSteps[$provisioningState] ?? str_replace('_', ' ', $provisioningState) }}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">
                                        @if ($provisioningState === 'failed')
                                            {{ __('This install is paused until the server access issue is fixed.') }}
                                        @else
                                            {{ __('This page updates live as the installer moves through each step.') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Completion rule') }}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('The site is considered ready as soon as either the testing URL or the real domain responds.') }}</p>
                                </div>

                                @if ($provisioningState === 'failed')
                                    <div>
                                        <button
                                            type="button"
                                            wire:click="retryProvisioning"
                                            wire:loading.attr="disabled"
                                            class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="retryProvisioning">{{ __('Retry provisioning') }}</span>
                                            <span wire:loading wire:target="retryProvisioning">{{ __('Retrying...') }}</span>
                                        </button>
                                        <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('Use this after fixing root SSH access or passwordless sudo on the server.') }}</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('Provisioning steps') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('A compact install timeline showing what is done, what is running, and what comes next.') }}</p>
                            </div>
                            <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                {{ max(1, $currentStepIndex + 1) }} / {{ count($statusSteps) }}
                            </div>
                        </div>
                        <div class="mt-6 space-y-4">
                            @foreach ($statusSteps as $key => $label)
                                @php
                                    $loopIndex = array_search($key, $stepKeys, true);
                                    $isDone = $loopIndex !== false && $loopIndex < $currentStepIndex;
                                    $isCurrent = $key === $provisioningState;
                                @endphp
                                <div class="flex gap-4 rounded-2xl px-1 py-1">
                                    <div class="flex flex-col items-center">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold shadow-sm {{ $isCurrent ? 'bg-slate-900 text-white ring-4 ring-slate-100' : ($isDone ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $isDone ? '✓' : $loop->iteration }}
                                        </div>
                                        @if (! $loop->last)
                                            <div class="mt-2 h-12 w-px {{ $isDone ? 'bg-emerald-200' : ($isCurrent ? 'bg-slate-300' : 'bg-slate-200') }}"></div>
                                        @endif
                                    </div>
                                    <div class="pb-4 pt-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium text-slate-900">{{ $label }}</p>
                                            @if ($isCurrent)
                                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{{ __('Live') }}</span>
                                            @elseif ($isDone)
                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ __('Done') }}</span>
                                            @endif
                                        </div>
                                        @if ($isCurrent)
                                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('This is the active install step right now.') }}</p>
                                        @elseif ($isDone)
                                            <p class="mt-1 text-sm leading-6 text-emerald-700">{{ __('Completed successfully.') }}</p>
                                        @else
                                            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('This will run automatically once the earlier steps finish.') }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('DNS and hostname readiness') }}</h3>
                                <p class="mt-1 text-sm text-slate-500">{{ __('Dply keeps checking both URLs and moves on as soon as one of them becomes reachable.') }}</p>
                            </div>
                            <div class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                {{ __('Either one can win') }}
                            </div>
                        </div>

                        @if ($testingHostname !== '')
                            <div class="mt-5 rounded-2xl border border-emerald-200 bg-[linear-gradient(180deg,#f0fdf4_0%,#ffffff_100%)] p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-700">{{ __('Temporary testing hostname') }}</p>
                                        <p class="mt-2 break-all font-mono text-sm text-emerald-950">{{ $testingHostname }}</p>
                                    </div>
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">{{ __('Preferred during install') }}</span>
                                </div>
                            </div>
                        @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                                <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                            </div>
                        @endif

                        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Primary domain') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-slate-900">{{ optional($site->primaryDomain())->hostname ?? '—' }}</dd>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">{{ __('Web server') }}</dt>
                                <dd class="mt-2 text-sm font-medium capitalize text-slate-900">{{ $site->webserver() }}</dd>
                            </div>
                        </dl>

                        @if ($hostChecks->isNotEmpty())
                            <div class="mt-5 space-y-3">
                                @foreach ($hostChecks as $check)
                                    <div class="rounded-2xl border {{ ($check['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50/70' : 'border-amber-200 bg-amber-50/70' }} p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="font-mono text-sm font-medium text-slate-900">{{ $check['hostname'] }}</p>
                                                <p class="mt-1 text-xs leading-5 {{ ($check['ok'] ?? false) ? 'text-emerald-800' : 'text-amber-900' }}">
                                                    {{ ($check['ok'] ?? false) ? __('This hostname is reachable and can finish the install.') : ($check['error'] ?? __('This hostname has not passed checks yet.')) }}
                                                </p>
                                            </div>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ ($check['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                {{ ($check['ok'] ?? false) ? __('Ready') : __('Waiting') }}
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                            <div>
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Provisioning console') }}</h3>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Persistent server-side step log for hostname setup, config writes, retries, and readiness checks.') }}</p>
                            </div>
                            <span class="rounded-full border border-brand-ink/10 bg-white px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-moss shadow-sm">
                                {{ $provisioningLog->count() }} {{ \Illuminate\Support\Str::plural('event', $provisioningLog->count()) }}
                            </span>
                        </div>

                        <div class="p-5 sm:p-6">
                            <pre class="max-h-[26rem] overflow-auto whitespace-pre-wrap break-all rounded-xl border border-brand-ink/10 bg-zinc-50 px-4 py-3 font-mono text-xs leading-6 text-brand-ink shadow-inner [scrollbar-color:rgb(82_82_91/0.45)_transparent]">{{ $provisioningConsoleOutput !== '' ? $provisioningConsoleOutput : __('No provisioning events have been recorded yet. Once the worker starts, step-by-step output will appear here.') }}</pre>
                        </div>
                    </div>
                </div>
            @else
            <div class="grid gap-8 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
                <aside class="lg:sticky lg:top-8">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-slate-900">{{ optional($site->primaryDomain())->hostname ?? $site->name }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ $server->ip_address ?? __('No IP recorded') }}</p>
                                </div>
                                @if ($site->visitUrl())
                                    <a href="{{ $site->visitUrl() }}" target="_blank" rel="noreferrer" class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                    </a>
                                @endif
                            </div>
                        </div>
                        <nav class="p-4">
                            <ul class="space-y-1.5">
                                @foreach ($sidebarItems as $item)
                                    @php
                                        $isExternal = $item['external'] ?? false;
                                        $href = $item['href'] ?? '#'.$item['id'];
                                    @endphp
                                    <li>
                                        <a
                                            href="{{ $href }}"
                                            @if ($isExternal) target="_blank" rel="noreferrer" @endif
                                            class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
                                        >
                                            <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0" />
                                            <span>{{ $item['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    </div>
                </aside>

                <div class="space-y-6">
            <div id="general" class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Status</h3>
                @if ($site->workspace)
                    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project context') }}</p>
                        <p class="mt-1">
                            {{ __('This site rolls up into the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.operations', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project operations') }}</a>
                            {{ __('for grouped health and activity, or') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate releases and shared variables.') }}
                        </p>
                    </div>
                @endif
                <p class="text-sm text-slate-600 mb-3">
                    {{ __('Show this site on a public') }}
                    <a href="{{ route('status-pages.index') }}" class="text-slate-800 font-medium hover:underline">{{ __('status page') }}</a>.
                </p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-slate-500">Provisioning</dt><dd class="font-medium capitalize">{{ $site->statusLabel() }}</dd></div>
                    <div><dt class="text-slate-500">Provisioning step</dt><dd class="font-medium capitalize">{{ str_replace('_', ' ', $site->provisioningState() ?? 'queued') }}</dd></div>
                    <div><dt class="text-slate-500">SSL</dt><dd class="font-medium capitalize">{{ $site->ssl_status }}</dd></div>
                    <div><dt class="text-slate-500">Document root (configured)</dt><dd class="font-mono text-xs break-all">{{ $site->document_root }}</dd></div>
                    <div><dt class="text-slate-500">Deploy path</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveRepositoryPath() }}</dd></div>
                    <div><dt class="text-slate-500">Nginx web root</dt><dd class="font-mono text-xs break-all">{{ $site->effectiveDocumentRootForNginx() }}</dd></div>
                    <div><dt class="text-slate-500">Deploy strategy</dt><dd class="font-medium">{{ $site->deploy_strategy }}</dd></div>
                    @if (!empty($site->meta['site_health_last_check_at']))
                        <div><dt class="text-slate-500">URL health (scheduler)</dt><dd class="font-medium">
                            @if (!empty($site->meta['site_health_last_ok']))
                                <span class="text-green-700">OK</span>
                            @else
                                <span class="text-red-700">Failed</span>
                            @endif
                            <span class="text-slate-500 text-xs font-normal"> · {{ $site->meta['site_health_last_check_at'] ?? '' }}</span>
                        </dd></div>
                    @endif
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">{{ __('Testing URL') }}</h3>

                @if ($testingHostname !== '')
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                        <p class="text-sm font-medium text-emerald-900">
                            {{ $site->isReadyForTraffic() ? __('Temporary hostname ready') : __('Temporary hostname assigned') }}
                        </p>
                        <p class="mt-2 break-all font-mono text-sm text-emerald-950">{{ $testingHostname }}</p>
                        <p class="mt-2 text-sm text-emerald-900">
                            @if ($site->isReadyForTraffic())
                                {{ __('Use this URL to test the site before the customer domain points here.') }}
                            @else
                                {{ __('Dply will keep checking this hostname and mark the site ready once it starts responding.') }}
                            @endif
                        </p>
                    </div>
                @elseif (($testingHostnameMeta['status'] ?? null) === 'failed')
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <p class="font-medium">{{ __('Temporary hostname could not be created') }}</p>
                        <p class="mt-1">{{ $testingHostnameMeta['error'] ?? __('Check the global DigitalOcean token and the configured testing domains.') }}</p>
                    </div>
                @else
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        {{ __('No temporary testing hostname is configured for this site yet.') }}
                    </div>
                @endif

                @if (! empty($site->meta['ssl_last_requested_domains']))
                    <p class="mt-3 text-xs text-slate-500">
                        {{ __('SSL currently targets: :domains', ['domains' => implode(', ', (array) $site->meta['ssl_last_requested_domains'])]) }}
                    </p>
                @endif
            </div>

            @php
                $supportedInstalledPhpVersions = collect($sitePhpData['installed_versions'])
                    ->filter(fn (array $version) => (bool) ($version['is_supported'] ?? false))
                    ->values();
            @endphp

            <div id="laravel" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="font-medium text-slate-900">PHP</h3>
                        <p class="mt-1 text-sm text-slate-600">Choose a site PHP version from the supported versions currently installed on this server and keep site-owned runtime limits here. OPcache, Composer auth, and extension management stay shared and server-owned on the server PHP workspace.</p>
                    </div>
                    <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 hover:text-slate-900">
                        {{ __('Open server PHP workspace') }}
                    </a>
                </div>

                @if ($sitePhpData['mismatch_version'])
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <p class="font-medium">{{ __('PHP version mismatch') }}</p>
                        <p class="mt-1 text-amber-800">{{ __('This site references PHP :version, but that version is not currently installed on this server.', ['version' => $sitePhpData['mismatch_version']]) }}</p>
                        <p class="mt-2">
                            <a href="{{ $sitePhpData['server_php_workspace_url'] }}" wire:navigate class="font-medium text-amber-900 underline">
                                {{ __('Install or switch versions on the server PHP page') }}
                            </a>
                        </p>
                    </div>
                @endif

                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <dt class="text-slate-500">Current site version</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ $sitePhpData['current_version_label'] ?? 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Installed on this server</dt>
                        <dd class="mt-1 font-medium text-slate-900">
                            @if ($supportedInstalledPhpVersions->isNotEmpty())
                                {{ $supportedInstalledPhpVersions->pluck('label')->implode(', ') }}
                            @else
                                {{ __('No supported installed versions recorded yet') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">OPcache</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ __('Shared at the server level; review runtime config on the server PHP workspace.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Composer auth</dt>
                        <dd class="mt-1 font-medium text-slate-900">{{ __('Shared Composer credentials are managed from the server PHP workspace.') }}</dd>
                    </div>
                </dl>

                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    <p class="font-medium text-slate-900">{{ __('Extensions') }}</p>
                    <p class="mt-1">{{ __('Extensions are server-owned and shared across sites on this machine. Use the server PHP workspace to review versions and extension entry points.') }}</p>
                </div>

                <form wire:submit="savePhpSettings" class="space-y-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <x-input-label for="php_version" value="PHP version" />
                            <select id="php_version" wire:model="php_version" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                @foreach ($supportedInstalledPhpVersions as $version)
                                    <option value="{{ $version['id'] }}">{{ $version['label'] }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('php_version')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="php_memory_limit" value="Memory limit" />
                            <x-text-input id="php_memory_limit" wire:model="php_memory_limit" class="mt-1 block w-full font-mono text-sm" placeholder="512M" />
                            <x-input-error :messages="$errors->get('php_memory_limit')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="php_upload_max_filesize" value="Upload max filesize" />
                            <x-text-input id="php_upload_max_filesize" wire:model="php_upload_max_filesize" class="mt-1 block w-full font-mono text-sm" placeholder="64M" />
                            <x-input-error :messages="$errors->get('php_upload_max_filesize')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="php_max_execution_time" value="Max execution time" />
                            <x-text-input id="php_max_execution_time" wire:model="php_max_execution_time" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                            <x-input-error :messages="$errors->get('php_max_execution_time')" class="mt-1" />
                        </div>
                    </div>

                    <x-primary-button type="submit">Save PHP settings</x-primary-button>
                </form>
            </div>

            <div id="dns" class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-medium text-slate-900 mb-3">Domains</h3>
                <ul class="divide-y divide-slate-100 mb-4">
                    @foreach ($site->domains as $d)
                        <li class="py-2 flex justify-between items-center gap-2">
                            <span class="font-mono text-sm">
                                {{ $d->hostname }}
                                @if ($d->is_primary)<span class="text-slate-400">(primary)</span>@endif
                                @if ($d->hostname === $testingHostname)<span class="text-slate-400">(testing)</span>@endif
                            </span>
                            @if (! $d->is_primary)
                                @if ($d->hostname === $testingHostname)
                                    <span class="text-xs text-slate-400">{{ __('Managed by Dply') }}</span>
                                @else
                                    <button type="button" wire:click="openConfirmActionModal('removeDomain', ['{{ $d->id }}'], @js(__('Remove domain')), @js(__('Remove this domain?')), @js(__('Remove domain')), true)" class="text-red-600 text-sm hover:underline">Remove</button>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addDomain" class="flex flex-wrap gap-2 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <x-input-label for="new_domain_hostname" value="Add domain" />
                        <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
                        <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" class="!py-2">Add</x-primary-button>
                </form>
            </div>

            <div id="http-stack" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Nginx (HTTP)</h3>
                <p class="text-sm text-slate-600">Writes a vhost under <code class="bg-slate-100 px-1 rounded text-xs">sites-available</code>, symlinks to <code class="bg-slate-100 px-1 rounded text-xs">sites-enabled</code>, runs <code class="bg-slate-100 px-1 rounded text-xs">nginx -t</code> and reloads. Server must have Nginx installed; PHP sites need matching PHP-FPM.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="installNginx" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="installNginx">Install / update Nginx site</span>
                        <span wire:loading wire:target="installNginx" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Working…
                        </span>
                    </button>
                @else
                    <p class="text-sm text-amber-700">SSH key required on the server record.</p>
                @endif
            </div>

            <div id="ssl" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Let’s Encrypt (Certbot)</h3>
                <p class="text-sm text-slate-600">Run after HTTP vhost works and DNS points here. Uses <code class="bg-slate-100 px-1 rounded text-xs">certbot --nginx</code>. Set <code class="bg-slate-100 px-1 rounded text-xs">DPLY_CERTBOT_EMAIL</code> in <code class="bg-slate-100 px-1 rounded text-xs">.env</code> or ensure your user/org has an email.</p>
                @if ($server->isReady() && $server->ssh_private_key)
                    <button type="button" wire:click="issueSsl" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-emerald-800 text-white text-sm font-medium rounded-md hover:bg-emerald-900 disabled:opacity-50">
                        <span wire:loading.remove wire:target="issueSsl">Issue / renew SSL</span>
                        <span wire:loading wire:target="issueSsl" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Certbot…
                        </span>
                    </button>
                @endif
            </div>

            <div id="repository" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Git & deploy</h3>
                <form wire:submit="saveGit" class="space-y-3">
                    <div>
                        <x-input-label for="git_repository_url" value="Repository URL" />
                        <x-text-input id="git_repository_url" wire:model="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                    </div>
                    <div>
                        <x-input-label for="git_branch" value="Branch" />
                        <x-text-input id="git_branch" wire:model="git_branch" class="mt-1 block w-full w-48" />
                    </div>
                    <div>
                        <x-input-label for="post_deploy_command" value="Post-deploy command (after pipeline steps below)" />
                        <textarea id="post_deploy_command" wire:model="post_deploy_command" rows="3" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-sm" placeholder="composer install --no-dev && php artisan migrate --force"></textarea>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-primary-button type="submit">Save</x-primary-button>
                        <button type="button" wire:click="generateDeployKey" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Generate deploy key</button>
                    </div>
                </form>

                <div class="border-t border-slate-100 pt-4 mt-4 space-y-3">
                    <h4 class="text-sm font-medium text-slate-900">Deploy pipeline</h4>
                    <p class="text-sm text-slate-600">Optional ordered steps run on the server after the <code class="text-xs bg-slate-100 px-1 rounded">after_clone</code> hooks and before the post-deploy command. On <strong>atomic</strong> deploys they run in the new release directory before the <code class="text-xs bg-slate-100 px-1 rounded">current</code> symlink is updated.</p>
                    @if ($site->deploySteps->isNotEmpty())
                        <ol class="list-decimal list-inside text-sm space-y-2 text-slate-800">
                            @foreach ($site->deploySteps->sortBy('sort_order') as $step)
                                <li class="flex flex-wrap justify-between gap-2 items-start border-b border-slate-50 pb-2">
                                    <span>
                                        <span class="font-mono text-xs">{{ $step->step_type }}</span>
                                        <span class="text-slate-400 text-xs"> · {{ (int) ($step->timeout_seconds ?? 900) }}s</span>
                                        @if ($step->custom_command)
                                            <span class="text-slate-500"> — {{ \Illuminate\Support\Str::limit($step->custom_command, 80) }}</span>
                                        @endif
                                    </span>
                                    <span class="flex gap-2 shrink-0">
                                        <button type="button" wire:click="moveDeployStepUp({{ $step->id }})" class="text-slate-600 text-xs hover:underline">Up</button>
                                        <button type="button" wire:click="moveDeployStepDown({{ $step->id }})" class="text-slate-600 text-xs hover:underline">Down</button>
                                        <button type="button" wire:click="deleteDeployPipelineStep({{ $step->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <form wire:submit="addDeployPipelineStep" class="flex flex-wrap gap-2 items-end">
                        <div>
                            <label for="new_deploy_step_type" class="block text-xs font-medium text-slate-600 mb-1">Step</label>
                            <select id="new_deploy_step_type" wire:model="new_deploy_step_type" class="rounded-md border-slate-300 shadow-sm text-sm min-w-[200px]">
                                @foreach (\App\Models\SiteDeployStep::typeLabels() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-1 min-w-[180px]">
                            <label for="new_deploy_step_command" class="block text-xs font-medium text-slate-600 mb-1">npm script / custom (if needed)</label>
                            <input type="text" id="new_deploy_step_command" wire:model="new_deploy_step_command" class="w-full rounded-md border-slate-300 shadow-sm text-sm font-mono" placeholder="build or full shell for custom" />
                            <x-input-error :messages="$errors->get('new_deploy_step_command')" class="mt-1" />
                        </div>
                        <div>
                            <label for="new_deploy_step_timeout" class="block text-xs font-medium text-slate-600 mb-1">Timeout (s)</label>
                            <input type="number" id="new_deploy_step_timeout" wire:model="new_deploy_step_timeout" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                        </div>
                        <x-primary-button type="submit" class="!py-2">Add step</x-primary-button>
                    </form>
                </div>
                @if ($site->git_deploy_key_public)
                    <div>
                        <p class="text-sm text-slate-600 mb-1">Public key (add to GitHub / GitLab deploy keys):</p>
                        <pre class="bg-slate-900 text-green-400 p-3 rounded text-xs overflow-x-auto whitespace-pre-wrap">{{ $site->git_deploy_key_public }}</pre>
                    </div>
                @endif
                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="button" wire:click="deployNow" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="deployNow">Deploy now (sync)</span>
                        <span wire:loading wire:target="deployNow" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Deploying…
                        </span>
                    </button>
                    <button type="button" wire:click="queueDeploy" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Queue deploy (queue worker)</button>
                </div>
            </div>

            <div id="deploy-settings" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-medium text-slate-900">Deployment &amp; Nginx tuning</h3>
                <p class="text-sm text-slate-600"><strong>Atomic</strong> deploys clone into <code class="text-xs bg-slate-100 px-1 rounded">releases/&lt;timestamp&gt;</code> and flip a <code class="text-xs bg-slate-100 px-1 rounded">current</code> symlink. Nginx web root becomes <code class="text-xs bg-slate-100 px-1 rounded">…/current/public</code>. Enable Laravel scheduler here, then sync crontab on the server page.</p>
                @if ($site->workspace)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project delivery context') }}</p>
                        <p class="mt-1">
                            {{ __('This site belongs to the :project project.', ['project' => $site->workspace->name]) }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('Open project delivery') }}</a>
                            {{ __('to review shared variables, coordinated deploy batches, and delivery notes before changing this site.') }}
                        </p>
                    </div>
                @endif
                <form wire:submit="saveDeploymentSettings" class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Deploy strategy" />
                            <select wire:model="deploy_strategy" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm text-sm">
                                <option value="simple">Simple (git in deploy path)</option>
                                <option value="atomic">Atomic (releases + current symlink)</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="releases_to_keep" value="Releases to keep" />
                            <x-text-input id="releases_to_keep" type="number" wire:model="releases_to_keep" class="mt-1 w-24" min="1" max="50" />
                        </div>
                        <div>
                            <x-input-label for="deployment_environment" value="Env group (for key/value vars)" />
                            <x-text-input id="deployment_environment" wire:model="deployment_environment" class="mt-1 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label for="octane_port" value="Octane port (PHP sites only; proxies to Swoole/RoadRunner)" />
                            <x-text-input id="octane_port" wire:model="octane_port" placeholder="8000" class="mt-1 block w-full font-mono text-sm" />
                        </div>
                        <div>
                            <x-input-label for="php_fpm_user" value="PHP-FPM pool user (note in config)" />
                            <x-text-input id="php_fpm_user" wire:model="php_fpm_user" class="mt-1 block w-full text-sm" placeholder="www-data" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="laravel_scheduler" class="rounded border-slate-300">
                        Laravel scheduler (<code class="text-xs bg-slate-100 px-1">schedule:run</code> every minute via server crontab)
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="restart_supervisor_programs_after_deploy" class="rounded border-slate-300">
                        Restart Supervisor programs after successful deploy (programs linked to this site or server-wide on the same machine)
                    </label>
                    <div>
                        <x-input-label for="nginx_extra_raw" value="Extra Nginx inside server block (advanced)" />
                        <textarea id="nginx_extra_raw" wire:model="nginx_extra_raw" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="# location /foo { ... }"></textarea>
                    </div>
                    <x-primary-button type="submit">Save</x-primary-button>
                </form>
            </div>

            <div id="env-vars" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Environment variables (key / value)</h3>
                <p class="text-sm text-slate-600">Merged with project-level variables and the raw .env draft below for the selected environment. Values are encrypted in Dply.</p>
                @if ($site->workspace && $site->workspace->variables->isNotEmpty())
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
                        <p class="font-medium">{{ __('Inherited project variables') }}</p>
                        <p class="mt-1 text-sky-900">{{ __('These values are merged into the final .env for this site. Keep shared values on the project, then add a site variable only when this site needs an override.') }}</p>
                        <ul class="mt-3 space-y-1">
                            @foreach ($site->workspace->variables as $projectVariable)
                                <li>
                                    <span class="font-mono text-xs">{{ $projectVariable->env_key }}</span>
                                    <span class="text-sky-800">·</span>
                                    <span>{{ $projectVariable->is_secret ? __('secret') : __('shared value') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3">
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-sky-950 hover:underline">{{ __('Manage project variables') }}</a>
                        </p>
                    </div>
                @endif
                @if ($site->environmentVariables->isNotEmpty())
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($site->environmentVariables as $ev)
                            <li class="py-2 flex justify-between gap-2">
                                <span><span class="font-mono">{{ $ev->env_key }}</span> <span class="text-slate-400">({{ $ev->environment }})</span> = <span class="text-slate-600">••••</span></span>
                                <button type="button" wire:click="deleteEnvironmentVariable({{ $ev->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addEnvironmentVariable" class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-end">
                    <div>
                        <x-input-label for="new_env_key" value="KEY" />
                        <x-text-input id="new_env_key" wire:model="new_env_key" class="mt-1 font-mono text-sm" placeholder="APP_DEBUG" />
                        <x-input-error :messages="$errors->get('new_env_key')" />
                    </div>
                    <div>
                        <x-input-label for="new_env_value" value="Value" />
                        <x-text-input id="new_env_value" wire:model="new_env_value" class="mt-1 font-mono text-sm" type="password" autocomplete="off" />
                    </div>
                    <div>
                        <x-input-label for="new_env_environment" value="Environment" />
                        <x-text-input id="new_env_environment" wire:model="new_env_environment" class="mt-1 text-sm" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-primary-button type="submit" class="!py-2">Save variable</x-primary-button>
                    </div>
                </form>
            </div>

            <div id="redirects" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Redirects (exact path)</h3>
                <p class="text-sm text-slate-600">Creates <code class="text-xs bg-slate-100 px-1">location = /path</code> blocks. Re-run Install Nginx after changes.</p>
                @if ($site->redirects->isNotEmpty())
                    <ul class="text-sm space-y-1">
                        @foreach ($site->redirects as $r)
                            <li class="flex justify-between gap-2 font-mono text-xs">
                                <span>{{ $r->from_path }} → {{ $r->to_url }} ({{ $r->status_code }})</span>
                                <button type="button" wire:click="deleteRedirectRule({{ $r->id }})" class="text-red-600 hover:underline shrink-0">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addRedirectRule" class="flex flex-wrap gap-2 items-end">
                    <x-text-input wire:model="new_redirect_from" placeholder="/old" class="font-mono text-sm w-32" />
                    <x-text-input wire:model="new_redirect_to" placeholder="https://…" class="font-mono text-sm flex-1 min-w-[200px]" />
                    <select wire:model.number="new_redirect_code" class="rounded-md border-slate-300 text-sm">
                        <option value="301">301</option>
                        <option value="302">302</option>
                        <option value="307">307</option>
                        <option value="308">308</option>
                    </select>
                    <x-primary-button type="submit" class="!py-2">Add</x-primary-button>
                </form>
            </div>

            <div id="deploy-hooks" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Deploy hooks (bash)</h3>
                <p class="text-sm text-slate-600"><strong>before_clone</strong> runs in the deploy base directory. <strong>after_clone</strong> in the new release. <strong>after_activate</strong> after the <code class="text-xs bg-slate-100 px-1">current</code> symlink updates (atomic only).</p>
                @if ($site->deployHooks->isNotEmpty())
                    <ul class="space-y-2 text-sm">
                        @foreach ($site->deployHooks as $h)
                            <li class="border border-slate-100 rounded p-2">
                                <div class="flex justify-between mb-1">
                                    <span class="font-medium">{{ $h->phase }} #{{ $h->sort_order }} <span class="text-slate-500 font-normal">· {{ (int) ($h->timeout_seconds ?? config('dply.default_deploy_hook_timeout_seconds', 900)) }}s</span></span>
                                    <button type="button" wire:click="deleteDeployHook({{ $h->id }})" class="text-red-600 text-xs hover:underline">Remove</button>
                                </div>
                                <pre class="text-xs bg-slate-900 text-green-400 p-2 rounded overflow-x-auto whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($h->script, 500) }}</pre>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addDeployHook" class="space-y-2">
                    <select wire:model="new_hook_phase" class="rounded-md border-slate-300 text-sm">
                        <option value="before_clone">before_clone</option>
                        <option value="after_clone">after_clone</option>
                        <option value="after_activate">after_activate</option>
                    </select>
                    <div class="flex flex-wrap gap-2 items-center">
                        <x-text-input type="number" wire:model="new_hook_order" class="w-24 text-sm" title="sort order" />
                        <div>
                            <label class="block text-xs text-slate-600 mb-0.5">Timeout (s)</label>
                            <input type="number" wire:model="new_hook_timeout_seconds" min="30" max="3600" class="w-24 rounded-md border-slate-300 shadow-sm text-sm" />
                        </div>
                    </div>
                    <textarea wire:model="new_hook_script" rows="4" class="w-full rounded-md border-slate-300 font-mono text-xs" placeholder="#!/usr/bin/env bash"></textarea>
                    <x-primary-button type="submit" class="!py-2">Add hook</x-primary-button>
                </form>
            </div>

            @if ($site->deploy_strategy === 'atomic')
                <div id="commits" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                    <h3 class="font-medium text-slate-900">Releases &amp; rollback</h3>
                    @if ($site->releases->isEmpty())
                        <p class="text-sm text-slate-500">No recorded releases yet. Deploy once with atomic strategy.</p>
                    @else
                        <ul class="text-sm space-y-2">
                            @foreach ($site->releases as $rel)
                                <li class="flex justify-between items-center border border-slate-100 rounded px-3 py-2">
                                    <div>
                                        <span class="font-mono text-xs">{{ $rel->folder }}</span>
                                        @if ($rel->is_active)<span class="text-green-600 text-xs ml-2">active</span>@endif
                                        @if ($rel->git_sha)<div class="font-mono text-xs text-slate-500">{{ $rel->git_sha }}</div>@endif
                                    </div>
                                    @if (! $rel->is_active)
                                        <button type="button" wire:click="openConfirmActionModal('rollbackRelease', ['{{ $rel->id }}'], @js(__('Rollback release')), @js(__('Point current symlink at this release?')), @js(__('Rollback')), true)" class="text-slate-800 text-xs hover:underline">Rollback</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div id="notifications" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Deploy webhook</h3>
                <p class="text-sm text-slate-600"><strong>Recommended:</strong> send <code class="text-xs bg-slate-100 px-1 rounded">X-Dply-Timestamp</code> (unix seconds) and <code class="text-xs bg-slate-100 px-1 rounded">X-Dply-Signature: sha256=&lt;hmac&gt;</code> where HMAC is <code class="text-xs bg-slate-100 px-1 rounded">hash_hmac('sha256', "{timestamp}." . raw_body, secret)</code>. Replays of the same payload within 15 minutes return <code class="text-xs">409</code>. <strong>Legacy:</strong> signature over raw body only (no timestamp) is still accepted.</p>
                <p class="text-sm font-mono break-all bg-slate-50 p-2 rounded">{{ $deployHookUrl }}</p>
                @if ($revealed_webhook_secret)
                    <p class="text-sm text-amber-800 font-medium">Copy your new secret now:</p>
                    <pre class="bg-slate-900 text-amber-200 p-3 rounded text-xs overflow-x-auto">{{ $revealed_webhook_secret }}</pre>
                @else
                    <p class="text-sm text-slate-500">Secret is stored encrypted. Rotate to see a new one.</p>
                @endif
                <button type="button" wire:click="regenerateWebhookSecret" class="text-sm text-slate-700 underline">Rotate webhook secret</button>
                <form wire:submit="saveWebhookSecurity" class="space-y-2 border-t border-slate-100 pt-4 mt-4">
                    <x-input-label for="webhook_allowed_ips_text" value="Optional IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)" />
                    <textarea id="webhook_allowed_ips_text" wire:model="webhook_allowed_ips_text" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="203.0.113.10&#10;192.0.2.0/24"></textarea>
                    <x-input-error :messages="$errors->get('webhook_allowed_ips_text')" class="mt-1" />
                    <x-primary-button type="submit" class="!py-2 text-sm">Save allow list</x-primary-button>
                </form>
            </div>

            <div id="logs" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Webhook delivery log</h3>
                <p class="text-sm text-slate-600">Recent inbound deploy webhook attempts (signature checks, IP allow list, etc.).</p>
                @if ($site->webhookDeliveryLogs->isEmpty())
                    <p class="text-sm text-slate-500">No deliveries recorded yet.</p>
                @else
                    <ul class="text-xs font-mono space-y-1 border border-slate-100 rounded-md divide-y divide-slate-100">
                        @foreach ($site->webhookDeliveryLogs as $log)
                            <li class="px-3 py-2 flex flex-wrap gap-2 justify-between">
                                <span>{{ $log->created_at->diffForHumans() }}</span>
                                <span class="text-slate-600">{{ $log->request_ip ?? '—' }}</span>
                                <span class="text-slate-800">{{ $log->http_status }} · {{ $log->outcome }}</span>
                                @if ($log->detail)
                                    <span class="text-slate-500 w-full">{{ $log->detail }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div id="deployment-log" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3" wire:poll.10s>
                <h3 class="font-medium text-slate-900">Deployment log</h3>
                @if ($site->workspace)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                        <p class="font-medium text-slate-900">{{ __('Project delivery context') }}</p>
                        <p class="mt-1">
                            {{ __('Use this log for site-specific output, then') }}
                            <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ __('open project delivery') }}</a>
                            {{ __('to coordinate shared deploy batches, compare related site rollouts, and review project-level delivery notes for :project.', ['project' => $site->workspace->name]) }}
                        </p>
                    </div>
                @endif
                @if ($site->deployments->isEmpty())
                    <p class="text-sm text-slate-500">No deployments yet.</p>
                @else
                    <ul class="space-y-4">
                        @foreach ($site->deployments as $dep)
                            <li class="border border-slate-200 rounded-md p-3 text-sm">
                                <div class="flex flex-wrap justify-between gap-2 mb-2">
                                    @php
                                        $st = $dep->status;
                                        $cls = match ($st) {
                                            'success' => 'text-green-700',
                                            'failed' => 'text-red-700',
                                            'skipped' => 'text-amber-700',
                                            'running' => 'text-blue-700',
                                            default => 'text-slate-700',
                                        };
                                    @endphp
                                    <span class="font-medium capitalize">{{ $dep->trigger }} · <span class="{{ $cls }}">{{ $st }}</span></span>
                                    <span class="text-slate-500 text-xs">{{ $dep->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($dep->git_sha)
                                    <p class="text-xs font-mono text-slate-600 mb-1">{{ $dep->git_sha }}</p>
                                @endif
                                @if ($dep->log_output)
                                    <pre class="bg-slate-900 text-slate-200 p-2 rounded text-xs overflow-x-auto max-h-48 overflow-y-auto whitespace-pre-wrap">{{ \Illuminate\Support\Str::limit($dep->log_output, 8000) }}</pre>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div id="env-draft" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-medium text-slate-900">Environment (.env)</h3>
                <p class="text-sm text-slate-600">Draft is stored encrypted. Push merges <strong>project variables</strong>, then <strong>site key/value variables</strong> (for <code class="text-xs bg-slate-100 px-1">{{ $site->deployment_environment }}</code>) with this draft and writes <code class="text-xs bg-slate-100 px-1">{{ $site->effectiveEnvDirectory() }}/.env</code>.</p>
                @if ($site->workspace)
                    <p class="text-sm text-slate-500">
                        {{ __('For shared settings across multiple sites in this project, prefer storing them at the project level first.') }}
                        <a href="{{ route('projects.delivery', $site->workspace) }}" wire:navigate class="font-medium text-slate-700 hover:text-slate-900">{{ __('Open project delivery') }}</a>
                    </p>
                @endif
                <textarea wire:model="env_file_content" rows="8" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="APP_NAME=…"></textarea>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="saveEnvDraft" class="px-4 py-2 border border-slate-300 rounded-md text-sm text-slate-700 bg-white hover:bg-slate-50">Save draft in Dply</button>
                    <button type="button" wire:click="pushEnvToServer" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="pushEnvToServer">Push .env to server</span>
                        <span wire:loading wire:target="pushEnvToServer" class="inline-flex items-center gap-2">
                            <x-spinner variant="white" size="sm" />
                            Pushing…
                        </span>
                    </button>
                </div>
            </div>

            @can('delete', $site)
                <div id="manage" class="flex justify-between items-center">
                    <button type="button" wire:click="openConfirmActionModal('deleteSite', [], @js(__('Delete site')), @js(__('Delete this site from Dply? A background job removes Nginx vhost, optional releases/repo/cert (see DPLY_* env flags), supervisor rows tied to this site, deploy SSH key, and re-syncs server crontab.')), @js(__('Delete site')), true)" class="text-red-600 hover:underline text-sm">Delete site</button>
                </div>
            @endcan
                </div>
            </div>
            @endif
        </div>

        <x-slot name="modals">
            @include('livewire.partials.confirm-action-modal')
        </x-slot>
    </div>
</div>
