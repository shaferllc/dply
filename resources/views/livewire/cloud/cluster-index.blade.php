<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Cloud Clusters</h1>
            <p class="mt-1 text-sm text-gray-500">Manage your dply Cloud Kubernetes clusters.</p>
        </div>
        <a
            href="{{ route('cloud.clusters.create') }}"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Cluster
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <div class="flex flex-col sm:flex-row gap-4">
            <!-- Search -->
            <div class="flex-1">
                <label for="search" class="sr-only">Search clusters</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input
                        type="text"
                        id="search"
                        wire:model.live.debounce.300ms="search"
                        class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                        placeholder="Search by name or region..."
                    >
                </div>
            </div>

            <!-- Status Filter -->
            <div class="flex gap-2">
                @foreach ([['id' => 'all', 'label' => 'All', 'count' => $this->statusCounts['all']], ['id' => 'ready', 'label' => 'Ready', 'count' => $this->statusCounts['ready']], ['id' => 'pending', 'label' => 'Pending', 'count' => $this->statusCounts['pending']], ['id' => 'error', 'label' => 'Error', 'count' => $this->statusCounts['error']]] as $filter)
                    <button
                        wire:click="$set('statusFilter', '{{ $filter['id'] }}')"
                        @class([
                            'px-3 py-2 text-sm font-medium rounded-lg transition-colors',
                            'bg-indigo-100 text-indigo-700' => $statusFilter === $filter['id'],
                            'text-gray-700 hover:bg-gray-100' => $statusFilter !== $filter['id'],
                        ])
                    >
                        {{ $filter['label'] }}
                        <span @class([
                            'ml-1.5 px-2 py-0.5 text-xs rounded-full',
                            'bg-indigo-200 text-indigo-800' => $statusFilter === $filter['id'],
                            'bg-gray-100 text-gray-600' => $statusFilter !== $filter['id'],
                        ])>
                            {{ $filter['count'] }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Clusters List -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        @if ($this->clusters->count() > 0)
            <div class="divide-y divide-gray-200">
                @foreach ($this->clusters as $cluster)
                    <div class="p-6 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0">
                                    <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                        </svg>
                                    </div>
                                </div>
                                <div>
                                    <a href="{{ route('cloud.clusters.show', $cluster) }}" class="text-lg font-medium text-indigo-600 hover:text-indigo-500">
                                        {{ $cluster->name }}
                                    </a>
                                    <div class="flex items-center gap-3 mt-1 text-sm text-gray-500">
                                        <span>{{ $cluster->region }}</span>
                                        <span class="text-gray-300">|</span>
                                        <span>{{ $cluster->tierLabel() }} tier</span>
                                        <span class="text-gray-300">|</span>
                                        <span>{{ $cluster->cloud_apps_count }} {{ Str::plural('app', $cluster->cloud_apps_count) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                @php
                                    $statusColors = [
                                        'ready' => ['bg-green-100', 'text-green-800'],
                                        'pending' => ['bg-yellow-100', 'text-yellow-800'],
                                        'provisioning' => ['bg-blue-100', 'text-blue-800'],
                                        'error' => ['bg-red-100', 'text-red-800'],
                                        'deleting' => ['bg-gray-100', 'text-gray-800'],
                                    ];
                                    $colors = $statusColors[$cluster->status] ?? ['bg-gray-100', 'text-gray-800'];
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[0] }} {{ $colors[1] }}">
                                    {{ ucfirst($cluster->status) }}
                                </span>
                                <a href="{{ route('cloud.clusters.show', $cluster) }}" class="text-gray-400 hover:text-gray-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        </div>

                        @if ($cluster->isPending())
                            <div class="mt-4">
                                <div class="flex items-center gap-2 text-sm text-blue-600">
                                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                    </svg>
                                    <span>Provisioning in progress...</span>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="p-4 border-t border-gray-200">
                {{ $this->clusters->links() }}
            </div>
        @else
            <div class="p-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No clusters found</h3>
                <p class="mt-1 text-sm text-gray-500">
                    @if ($search || $statusFilter !== 'all')
                        No clusters match your current filters.
                        <button wire:click="$set('search', ''); $set('statusFilter', 'all')" class="text-indigo-600 hover:text-indigo-500 ml-1">Clear filters</button>
                    @else
                        Get started by creating your first dply Cloud cluster.
                    @endif
                </p>
                @if (!$search && $statusFilter === 'all')
                    <div class="mt-6">
                        <a
                            href="{{ route('cloud.clusters.create') }}"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Create Cluster
                        </a>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Info Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-900">Managed Kubernetes</h3>
                    <p class="mt-1 text-sm text-gray-500">Powered by DigitalOcean Kubernetes (DOKS)</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-900">Auto-scaling</h3>
                    <p class="mt-1 text-sm text-gray-500">Scale from 1 to 50 pods automatically</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-900">SSL Included</h3>
                    <p class="mt-1 text-sm text-gray-500">Automatic HTTPS for custom domains</p>
                </div>
            </div>
        </div>
    </div>
</div>
