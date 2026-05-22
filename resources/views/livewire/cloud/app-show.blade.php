<div class="max-w-7xl mx-auto space-y-6" wire:poll.30s="{{ $app->isDeploying() ? '' : '' }}">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-sm text-gray-500 mb-1">
                <a href="{{ route('cloud.clusters.show', $cluster) }}" class="hover:text-gray-700">{{ $cluster->name }}</a>
                <span>/</span>
                <span>{{ $app->name }}</span>
            </div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">{{ $app->name }}</h1>
                <span @class([
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                    'bg-green-100 text-green-800' => $app->isReady(),
                    'bg-blue-100 text-blue-800' => $app->isDeploying(),
                    'bg-yellow-100 text-yellow-800' => $app->status === 'pending',
                    'bg-red-100 text-red-800' => $app->status === 'error',
                    'bg-gray-100 text-gray-800' => !in_array($app->status, ['running', 'building', 'deploying', 'pending', 'error']),
                ])>
                    {{ ucfirst($app->status) }}
                </span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                {{ $app->runtimeLabel() }} • {{ $app->frameworkLabel() }}
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if ($app->defaultUrl())
                <a
                    href="{{ $app->defaultUrl() }}"
                    target="_blank"
                    class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Open App
                    <svg class="w-4 h-4 ml-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>
            @endif

            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="p-2 text-gray-400 hover:text-gray-500 rounded-lg hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                    </svg>
                </button>

                <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10">
                    <!-- Deploy -->
                    <button
                        @click="open = false"
                        class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                    >
                        Deploy Now
                    </button>

                    <!-- Environment Variables -->
                    <button
                        wire:click="$set('showEnvEditor', true)"
                        @click="open = false"
                        class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                    >
                        Environment Variables
                    </button>

                    <div class="border-t border-gray-100"></div>

                    <!-- Delete -->
                    <button
                        @click="open = false"
                        class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                    >
                        Delete App
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Messages -->
    @if (session()->has('success'))
        <div class="rounded-md bg-green-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Main Info -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Repository Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Repository</h3>
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v11a3 3 0 106 0V4a2 2 0 00-2-2H4zm1 14a1 1 0 100-2 1 1 0 000 2zm5-1.757l4.9-4.9a2 2 0 000-2.828L13.485 5.1a2 2 0 00-2.828 0L10 5.757v8.486zM16 18H9.071l6-6H16a2 2 0 012 2v2a2 2 0 01-2 2z" clip-rule="evenodd"/>
                    </svg>
                    <div class="flex-1">
                        <p class="font-medium text-gray-900">{{ $app->git_repository_url }}</p>
                        <p class="text-sm text-gray-500">Branch: {{ $app->git_branch }}</p>
                    </div>
                </div>
            </div>

            <!-- Deployment Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Latest Deployment</h3>
                    @if ($app->cloudDeploys()->count() > 1)
                        <a href="#" class="text-sm text-indigo-600 hover:text-indigo-500">View all {{ $app->cloudDeploys()->count() }} deploys</a>
                    @endif
                </div>

                @if ($this->latestDeploy)
                    <div class="space-y-4">
                        <div class="flex items-center gap-4">
                            <span @class([
                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                'bg-green-100 text-green-800' => $this->latestDeploy->isSuccessful(),
                                'bg-blue-100 text-blue-800' => $this->latestDeploy->isPending(),
                                'bg-red-100 text-red-800' => $this->latestDeploy->isFailed(),
                                'bg-gray-100 text-gray-800' => $this->latestDeploy->isRolledBack(),
                            ])>
                                {{ $this->latestDeploy->statusLabel() }}
                            </span>
                            <span class="text-sm text-gray-500">
                                {{ $this->latestDeploy->started_at->diffForHumans() }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500">Commit:</span>
                                <code class="ml-2 text-gray-900 bg-gray-100 px-2 py-0.5 rounded">{{ $this->latestDeploy->shortSha() }}</code>
                            </div>
                            <div>
                                <span class="text-gray-500">Branch:</span>
                                <span class="ml-2 text-gray-900">{{ $this->latestDeploy->git_branch }}</span>
                            </div>
                            @if ($this->latestDeploy->durationSeconds())
                                <div>
                                    <span class="text-gray-500">Duration:</span>
                                    <span class="ml-2 text-gray-900">{{ $this->latestDeploy->durationSeconds() }}s</span>
                                </div>
                            @endif
                            @if ($this->latestDeploy->container_image)
                                <div>
                                    <span class="text-gray-500">Image:</span>
                                    <span class="ml-2 text-gray-900 truncate">{{ $this->latestDeploy->container_image }}</span>
                                </div>
                            @endif
                        </div>

                        @if ($this->latestDeploy->isPending())
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="flex items-center gap-3">
                                    <svg class="animate-spin h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                    </svg>
                                    <span class="text-blue-700">Deployment in progress...</span>
                                </div>
                            </div>
                        @endif

                        @if ($this->latestDeploy->build_output)
                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                                    <span class="text-sm font-medium text-gray-700">Build Output</span>
                                </div>
                                <div class="bg-gray-900 p-4 max-h-64 overflow-y-auto">
                                    <pre class="text-xs text-gray-300 font-mono whitespace-pre-wrap">{{ $this->latestDeploy->build_output }}</pre>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-center py-8">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No deployments yet</h3>
                        <p class="mt-1 text-sm text-gray-500">Your first deployment will appear here.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Column - Details -->
        <div class="space-y-6">
            <!-- Resource Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Resources</h3>
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-gray-500">CPU Limit</dt>
                        <dd class="font-medium text-gray-900">{{ number_format($app->cpu_limit * 1000) }}m ({{ $app->cpu_limit }} vCPU)</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Memory Limit</dt>
                        <dd class="font-medium text-gray-900">{{ $app->memory_limit }} MB</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Replicas</dt>
                        <dd class="font-medium text-gray-900">{{ $app->min_replicas }} - {{ $app->max_replicas }} pods</dd>
                    </div>
                </dl>
            </div>

            <!-- Domains Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Domains</h3>
                @if (count($this->domains) > 0)
                    <ul class="space-y-2">
                        @foreach ($this->domains as $domain)
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span class="font-medium text-gray-900">{{ $domain }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-500">No custom domains configured.</p>
                    <p class="text-sm text-gray-400 mt-1">Using cluster subdomain.</p>
                @endif
            </div>

            <!-- Environment Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Environment</h3>
                @if (count($this->envVars) > 0)
                    <p class="text-sm text-gray-600 mb-2">{{ count($this->envVars) }} variables configured</p>
                    <button
                        wire:click="$set('showEnvEditor', true)"
                        class="text-sm text-indigo-600 hover:text-indigo-500"
                    >
                        Manage variables
                    </button>
                @else
                    <p class="text-sm text-gray-500">No environment variables configured.</p>
                    <button
                        wire:click="$set('showEnvEditor', true)"
                        class="mt-2 text-sm text-indigo-600 hover:text-indigo-500"
                    >
                        Add variables
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Env Editor Modal -->
    @if ($showEnvEditor)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="$set('showEnvEditor', false)"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Environment Variables</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Environment variables are encrypted and injected into your application at runtime.
                        </p>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 px-4 py-2 border-b border-gray-200 flex justify-between text-sm font-medium text-gray-700">
                                <span>Key</span>
                                <span>Value</span>
                            </div>
                            <div class="divide-y divide-gray-200 max-h-64 overflow-y-auto">
                                @foreach ($this->envVars as $key => $value)
                                    <div class="px-4 py-3 flex justify-between items-center">
                                        <code class="text-sm text-gray-900">{{ $key }}</code>
                                        <span class="text-sm text-gray-500 font-mono">••••••••</span>
                                    </div>
                                @endforeach
                                @if (count($this->envVars) === 0)
                                    <div class="px-4 py-8 text-center text-sm text-gray-500">
                                        No environment variables configured.
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button
                            type="button"
                            wire:click="$set('showEnvEditor', false)"
                            class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
