<div class="max-w-7xl mx-auto space-y-6" wire:poll.30s="{{ $cluster->isPending() ? 'refreshStatus' : '' }}">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold text-gray-900">{{ $cluster->name }}</h1>
                <span @class([
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                    'bg-green-100 text-green-800' => $this->statusDisplay['color'] === 'green',
                    'bg-blue-100 text-blue-800' => $this->statusDisplay['color'] === 'blue',
                    'bg-yellow-100 text-yellow-800' => $this->statusDisplay['color'] === 'yellow',
                    'bg-red-100 text-red-800' => $this->statusDisplay['color'] === 'red',
                    'bg-gray-100 text-gray-800' => $this->statusDisplay['color'] === 'gray',
                ])>
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        @switch($this->statusDisplay['icon'])
                            @case('clock')
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                @break
                            @case('cog')
                                <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/>
                                @break
                            @case('check-circle')
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                @break
                            @default
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                        @endswitch
                    </svg>
                    {{ $this->statusDisplay['label'] }}
                </span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                {{ $this->regionName }} • {{ $cluster->tierLabel() }} tier
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if ($cluster->isReady())
                <a
                    href="{{ route('cloud.apps.create', $cluster) }}"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    New App
                </a>
            @endif

            <button
                wire:click="refreshStatus"
                wire:loading.attr="disabled"
                class="p-2 text-gray-400 hover:text-gray-500 rounded-lg hover:bg-gray-100"
                title="Refresh status"
            >
                <svg wire:loading.remove wire:target="refreshStatus" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <svg wire:loading wire:target="refreshStatus" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
            </button>

            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="p-2 text-gray-400 hover:text-gray-500 rounded-lg hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/>
                    </svg>
                </button>

                <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-10">
                    @if ($cluster->kubeconfigString())
                        <button
                            wire:click="downloadKubeconfig"
                            @click="open = false"
                            class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                        >
                            Download Kubeconfig
                        </button>
                    @endif
                    <button
                        wire:click="$set('confirmingDelete', true)"
                        @click="open = false"
                        class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                    >
                        Delete Cluster
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

    @if (session()->has('error'))
        <div class="rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                </div>
            </div>
        </div>
    @endif

    @if ($cluster->error_message)
        <div class="rounded-md bg-red-50 border border-red-200 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Provisioning Error</h3>
                    <p class="mt-2 text-sm text-red-700">{{ $cluster->error_message }}</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Provisioning Progress -->
    @if ($cluster->isPending())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center">
                <svg class="animate-spin h-8 w-8 text-indigo-600 mr-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Cluster is being provisioned</h2>
                    <p class="text-sm text-gray-500 mt-1">This typically takes 5-10 minutes. We'll automatically update when it's ready.</p>
                </div>
            </div>

            <div class="mt-4">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-indigo-600 h-2 rounded-full animate-pulse" style="width: 60%"></div>
                </div>
            </div>
        </div>
    @endif

    <!-- Cluster Details Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Info Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Cluster Info</h3>

            <dl class="space-y-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Region</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $this->regionName }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Tier</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $cluster->tierLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Node Size</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $this->nodePoolDetails['size'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Nodes</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ $this->nodePoolDetails['count'] }}
                        @if ($this->nodePoolDetails['autoscale'])
                            <span class="text-gray-500">(autoscaling {{ $this->nodePoolDetails['minNodes'] }}-{{ $this->nodePoolDetails['maxNodes'] }})</span>
                        @endif
                    </dd>
                </div>
                @if ($this->provisionDetails['doClusterVersion'])
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Kubernetes Version</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $this->provisionDetails['doClusterVersion'] }}</dd>
                    </div>
                @endif
                @if ($cluster->provisioned_at)
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Created</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $cluster->provisioned_at->diffForHumans() }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <!-- API Endpoint Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">API Access</h3>

            @if ($cluster->isReady())
                <div class="space-y-4">
                    @if ($this->provisionDetails['apiEndpoint'])
                        <div>
                            <dt class="text-sm font-medium text-gray-500">API Endpoint</dt>
                            <dd class="mt-1 text-sm font-mono text-gray-900 bg-gray-50 p-2 rounded truncate">
                                {{ $this->provisionDetails['apiEndpoint'] }}
                            </dd>
                        </div>
                    @endif
                    @if ($cluster->kubeconfigString())
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Kubeconfig</dt>
                            <dd class="mt-1">
                                <button
                                    wire:click="downloadKubeconfig"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                    Download
                                </button>
                            </dd>
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500">API access will be available once the cluster is ready.</p>
            @endif
        </div>

        <!-- Quick Actions Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>

            <div class="space-y-3">
                <a
                    href="{{ route('cloud.apps.create', $cluster) }}"
                    @class([
                        'block w-full px-4 py-2 rounded-lg text-sm font-medium text-center',
                        'bg-indigo-600 text-white hover:bg-indigo-700' => $cluster->isReady(),
                        'bg-gray-100 text-gray-400 cursor-not-allowed' => !$cluster->isReady(),
                    ])
                >
                    Deploy New App
                </a>

                <button
                    wire:click="refreshStatus"
                    wire:loading.attr="disabled"
                    class="block w-full px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    <span wire:loading.remove wire:target="refreshStatus">Refresh Status</span>
                    <span wire:loading wire:target="refreshStatus">Refreshing...</span>
                </button>

                <button
                    wire:click="$set('confirmingDelete', true)"
                    class="block w-full px-4 py-2 border border-red-300 rounded-lg text-sm font-medium text-red-700 hover:bg-red-50"
                >
                    Delete Cluster
                </button>
            </div>
        </div>
    </div>

    <!-- Apps Section -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Applications</h3>
                @if ($cluster->isReady())
                    <a
                        href="{{ route('cloud.apps.create', $cluster) }}"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700"
                    >
                        New App
                    </a>
                @endif
            </div>
        </div>

        @if ($this->apps->count() > 0)
            <div class="divide-y divide-gray-200">
                @foreach ($this->apps as $app)
                    <div class="p-6 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <a href="{{ route('cloud.apps.show', ['cluster' => $cluster, 'app' => $app]) }}" class="text-lg font-medium text-indigo-600 hover:text-indigo-500">
                                    {{ $app->name }}
                                </a>
                                <p class="text-sm text-gray-500 mt-1">
                                    {{ $app->runtimeLabel() }} • {{ $app->frameworkLabel() }}
                                    @if ($app->primaryDomain())
                                        • {{ $app->primaryDomain() }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <span @class([
                                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                    'bg-green-100 text-green-800' => $app->isReady(),
                                    'bg-blue-100 text-blue-800' => $app->isDeploying(),
                                    'bg-yellow-100 text-yellow-800' => $app->status === 'pending',
                                    'bg-red-100 text-red-800' => $app->status === 'error',
                                ])>
                                    {{ $app->status }}
                                </span>
                                <span class="text-sm text-gray-500">
                                    {{ $app->cloud_deploys_count }} deploys
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="p-4 border-t border-gray-200">
                {{ $this->apps->links() }}
            </div>
        @else
            <div class="p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No applications</h3>
                <p class="mt-1 text-sm text-gray-500">
                    @if ($cluster->isReady())
                        Get started by deploying your first application.
                    @else
                        Applications can be deployed once the cluster is ready.
                    @endif
                </p>
                @if ($cluster->isReady())
                    <div class="mt-6">
                        <a
                            href="{{ route('cloud.apps.create', $cluster) }}"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Deploy New App
                        </a>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Delete Confirmation Modal -->
    @if ($confirmingDelete)
        <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" wire:click="$set('confirmingDelete', false)"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Delete Cluster</h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Are you sure you want to delete the cluster <strong>{{ $cluster->name }}</strong>?
                                        This will permanently delete all applications, databases, and data associated with this cluster.
                                        This action cannot be undone.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button
                            type="button"
                            wire:click="deleteCluster"
                            wire:loading.attr="disabled"
                            class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            <span wire:loading.remove wire:target="deleteCluster">Delete</span>
                            <span wire:loading wire:target="deleteCluster">Deleting...</span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('confirmingDelete', false)"
                            wire:loading.attr="disabled"
                            class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
