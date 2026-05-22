<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Create Cloud Cluster</h1>
        <p class="mt-2 text-gray-600">Provision a managed Kubernetes cluster for your applications.</p>
    </div>

    <!-- Progress Indicator -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            @for ($i = 1; $i <= 3; $i++)
                <div class="flex items-center">
                    <div @class([
                        'w-10 h-10 rounded-full flex items-center justify-center font-medium',
                        'bg-indigo-600 text-white' => $step >= $i,
                        'bg-gray-200 text-gray-600' => $step < $i,
                    ])>
                        {{ $i }}
                    </div>
                    <div class="ml-3 hidden sm:block">
                        <p @class([
                            'text-sm font-medium',
                            'text-gray-900' => $step >= $i,
                            'text-gray-500' => $step < $i,
                        ])>
                            @switch($i)
                                @case(1) Name & Region @break
                                @case(2) Choose Tier @break
                                @case(3) Review & Create @break
                            @endswitch
                        </p>
                    </div>
                </div>
                @if ($i < 3)
                    <div class="flex-1 mx-4 h-1 bg-gray-200 rounded">
                        <div @class([
                            'h-1 bg-indigo-600 rounded transition-all duration-300',
                            'w-full' => $step > $i,
                            'w-0' => $step <= $i,
                        ])></div>
                    </div>
                @endif
            @endfor
        </div>
    </div>

    <!-- Error Messages -->
    @if ($errors->has('general'))
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-red-700">{{ $errors->first('general') }}</p>
        </div>
    @endif

    <!-- Step 1: Name & Region -->
    @if ($step === 1)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Cluster Details</h2>

            <!-- Name Input -->
            <div class="mb-6">
                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Cluster Name</label>
                <input
                    type="text"
                    id="name"
                    wire:model="name"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="my-production-cluster"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500">
                    Must start with a letter. Only letters, numbers, and hyphens allowed.
                </p>
            </div>

            <!-- Region Selection -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Region</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($this->availableRegions as $regionId => $regionInfo)
                        <button
                            wire:click="selectRegion('{{ $regionId }}')"
                            type="button"
                            @class([
                                'p-4 border rounded-lg text-left transition-all',
                                'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500' => $region === $regionId,
                                'border-gray-200 hover:border-gray-300 hover:bg-gray-50' => $region !== $regionId,
                            ])
                        >
                            <div class="font-medium text-gray-900">{{ $regionInfo['name'] }}</div>
                            <div class="text-sm text-gray-500 mt-1">{{ $regionId }}</div>
                        </button>
                    @endforeach
                </div>
                @error('region')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Provider Credential -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">DigitalOcean Account</label>
                @if (count($this->availableCredentials) > 0)
                    <div class="space-y-3">
                        @foreach ($this->availableCredentials as $credential)
                            <label class="flex items-center p-4 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                                <input
                                    type="radio"
                                    name="providerCredentialId"
                                    value="{{ $credential['id'] }}"
                                    wire:model="providerCredentialId"
                                    class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                >
                                <div class="ml-3">
                                    <span class="font-medium text-gray-900">{{ $credential['name'] }}</span>
                                    <span class="text-sm text-gray-500 ml-2">({{ $credential['provider'] }})</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                @else
                    <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-yellow-800">
                            No DigitalOcean accounts connected.
                            <a href="{{ route('credentials.server-providers') }}" class="underline font-medium">Add a provider</a>
                        </p>
                    </div>
                @endif
                @error('providerCredentialId')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Navigation -->
            <div class="flex justify-end">
                <button
                    wire:click="nextStep"
                    type="button"
                    class="px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Continue to Tier
                </button>
            </div>
        </div>
    @endif

    <!-- Step 2: Choose Tier -->
    @if ($step === 2)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Choose Your Tier</h2>
            <p class="text-gray-600 mb-6">Select the tier that matches your application's needs.</p>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                @foreach ($this->availableTiers as $tierId => $tierInfo)
                    <div
                        @class([
                            'relative p-6 border-2 rounded-xl transition-all cursor-pointer',
                            'border-indigo-500 bg-indigo-50' => $tier === $tierId,
                            'border-gray-200 hover:border-gray-300 bg-white' => $tier !== $tierId,
                        ])
                        wire:click="selectTier('{{ $tierId }}')"
                    >
                        @if ($tier === $tierId)
                            <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                                <span class="bg-indigo-600 text-white text-xs font-semibold px-3 py-1 rounded-full">
                                    Selected
                                </span>
                            </div>
                        @endif

                        <div class="text-center">
                            <h3 class="text-xl font-semibold text-gray-900">{{ $tierInfo['name'] }}</h3>
                            <p class="mt-2 text-sm text-gray-500">{{ $tierInfo['description'] }}</p>
                            <p class="mt-4 text-3xl font-bold text-gray-900">{{ $tierInfo['price'] }}</p>
                            <p class="text-sm text-gray-500">{{ $tierInfo['specs'] }}</p>
                        </div>

                        <ul class="mt-6 space-y-3">
                            @foreach ($tierInfo['features'] as $feature)
                                <li class="flex items-center text-sm text-gray-600">
                                    <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <!-- Navigation -->
            <div class="flex justify-between mt-8">
                <button
                    wire:click="previousStep"
                    type="button"
                    class="px-6 py-2 text-gray-700 font-medium hover:text-gray-900"
                >
                    Back
                </button>
                <button
                    wire:click="nextStep"
                    type="button"
                    class="px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Review & Create
                </button>
            </div>
        </div>
    @endif

    <!-- Step 3: Review & Create -->
    @if ($step === 3)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Review Your Cluster</h2>

            <div class="space-y-6">
                <!-- Cluster Name -->
                <div class="flex justify-between border-b border-gray-100 pb-4">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Cluster Name</p>
                        <p class="text-lg font-medium text-gray-900">{{ $name }}</p>
                    </div>
                    <button wire:click="$set('step', 1)" class="text-sm text-indigo-600 hover:text-indigo-500">Edit</button>
                </div>

                <!-- Region -->
                <div class="flex justify-between border-b border-gray-100 pb-4">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Region</p>
                        <p class="text-lg font-medium text-gray-900">
                            {{ $this->availableRegions[$region]['name'] ?? $region }}
                        </p>
                    </div>
                    <button wire:click="$set('step', 1)" class="text-sm text-indigo-600 hover:text-indigo-500">Edit</button>
                </div>

                <!-- Tier -->
                <div class="flex justify-between border-b border-gray-100 pb-4">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Tier</p>
                        <p class="text-lg font-medium text-gray-900">
                            {{ $this->availableTiers[$tier]['name'] ?? $tier }}
                        </p>
                        <p class="text-sm text-gray-500">{{ $this->availableTiers[$tier]['specs'] ?? '' }}</p>
                    </div>
                    <button wire:click="$set('step', 2)" class="text-sm text-indigo-600 hover:text-indigo-500">Edit</button>
                </div>

                <!-- Node Pool Spec -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <p class="text-sm font-medium text-gray-700 mb-2">Node Pool Configuration</p>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Node Size</dt>
                            <dd class="font-medium text-gray-900">{{ $this->nodePoolPreview['size'] ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Initial Nodes</dt>
                            <dd class="font-medium text-gray-900">{{ $this->nodePoolPreview['count'] ?? 'N/A' }}</dd>
                        </div>
                        @if ($this->nodePoolPreview['autoscale'] ?? false)
                            <div>
                                <dt class="text-gray-500">Autoscaling</dt>
                                <dd class="font-medium text-gray-900">Enabled ({{ $this->nodePoolPreview['min_nodes'] }}-{{ $this->nodePoolPreview['max_nodes'] }} nodes)</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <!-- Estimated Cost -->
                <div class="bg-indigo-50 rounded-lg p-4">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-sm font-medium text-indigo-700">Estimated Monthly Cost</p>
                            <p class="text-xs text-indigo-600 mt-1">Base cluster fee. Compute and storage billed separately based on usage.</p>
                        </div>
                        <p class="text-2xl font-bold text-indigo-900">{{ $this->estimatedMonthlyCost }}</p>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between mt-8">
                <button
                    wire:click="previousStep"
                    type="button"
                    class="px-6 py-2 text-gray-700 font-medium hover:text-gray-900"
                    @disabled($isCreating)
                >
                    Back
                </button>
                <button
                    wire:click="createCluster"
                    type="button"
                    @disabled($isCreating || empty($providerCredentialId))
                    @class([
                        'px-6 py-2 font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 flex items-center',
                        'bg-indigo-600 text-white hover:bg-indigo-700' => !$isCreating && !empty($providerCredentialId),
                        'bg-gray-300 text-gray-500 cursor-not-allowed' => $isCreating || empty($providerCredentialId),
                    ])
                >
                    @if ($isCreating)
                        <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Creating Cluster...
                    @else
                        Create Cluster
                    @endif
                </button>
            </div>

            @empty($providerCredentialId)
                <p class="mt-4 text-sm text-red-600 text-right">
                    Please connect a DigitalOcean account to continue.
                </p>
            @endempty
        </div>
    @endif
</div>
