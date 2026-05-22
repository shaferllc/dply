<div class="max-w-4xl mx-auto">
    <div class="mb-8">
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('cloud.clusters.show', $cluster) }}" class="hover:text-gray-700">{{ $cluster->name }}</a>
            <span>/</span>
            <span>New App</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">Create Application</h1>
        <p class="mt-2 text-gray-600">Deploy a new application to your {{ $cluster->name }} cluster.</p>
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
                                @case(1) Repository @break
                                @case(2) Runtime @break
                                @case(3) Resources @break
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

    @if (session()->has('warning'))
        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-yellow-800">{{ session('warning') }}</p>
        </div>
    @endif

    <!-- Step 1: Repository -->
    @if ($step === 1)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Application Repository</h2>

            <!-- Name Input -->
            <div class="mb-6">
                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Application Name</label>
                <input
                    type="text"
                    id="name"
                    wire:model="name"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="my-laravel-app"
                >
                @error('name')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500">
                    Must start with a letter. Only lowercase letters, numbers, and hyphens allowed.
                </p>
            </div>

            <!-- Repository URL -->
            <div class="mb-6">
                <label for="gitRepositoryUrl" class="block text-sm font-medium text-gray-700 mb-2">Repository URL</label>
                <input
                    type="url"
                    id="gitRepositoryUrl"
                    wire:model="gitRepositoryUrl"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="https://github.com/username/repo"
                >
                @error('gitRepositoryUrl')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500">
                    GitHub, GitLab, and Bitbucket repositories are supported.
                </p>
            </div>

            <!-- Branch -->
            <div class="mb-6">
                <label for="gitBranch" class="block text-sm font-medium text-gray-700 mb-2">Default Branch</label>
                <input
                    type="text"
                    id="gitBranch"
                    wire:model="gitBranch"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="main"
                >
                @error('gitBranch')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Auto-detect Option -->
            <div class="mb-6">
                <label class="flex items-center">
                    <input
                        type="checkbox"
                        wire:model="autoDetect"
                        class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                    >
                    <span class="ml-2 text-sm text-gray-700">Auto-detect runtime and framework from repository</span>
                </label>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between">
                <a
                    href="{{ route('cloud.clusters.show', $cluster) }}"
                    class="px-6 py-2 text-gray-700 font-medium hover:text-gray-900"
                >
                    Cancel
                </a>
                <button
                    wire:click="nextStep"
                    type="button"
                    @class([
                        'px-6 py-2 font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2',
                        'bg-indigo-600 text-white hover:bg-indigo-700' => !$isDetecting,
                        'bg-gray-400 text-white cursor-not-allowed' => $isDetecting,
                    ])
                    @disabled($isDetecting)
                >
                    @if ($isDetecting)
                        <span class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Detecting...
                        </span>
                    @else
                        Continue
                    @endif
                </button>
            </div>
        </div>
    @endif

    <!-- Step 2: Runtime -->
    @if ($step === 2)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Runtime & Framework</h2>
            <p class="text-gray-600 mb-6">
                @if ($autoDetect && $runtime)
                    Auto-detected from repository. Adjust if needed.
                @else
                    Select the runtime and framework for your application.
                @endif
            </p>

            <!-- Runtime Selection -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Runtime</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ($this->availableRuntimes as $runtimeKey => $runtimeLabel)
                        <button
                            wire:click="$set('runtime', '{{ $runtimeKey }}')"
                            type="button"
                            @class([
                                'p-4 border rounded-lg text-left transition-all',
                                'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500' => $runtime === $runtimeKey,
                                'border-gray-200 hover:border-gray-300 hover:bg-gray-50' => $runtime !== $runtimeKey,
                            ])
                        >
                            <div class="font-medium text-gray-900">{{ $runtimeLabel }}</div>
                        </button>
                    @endforeach
                </div>
                @error('runtime')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Framework Selection -->
            @if (count($this->availableFrameworks) > 0)
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Framework</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach ($this->availableFrameworks as $frameworkKey => $frameworkLabel)
                            <button
                                wire:click="$set('framework', '{{ $frameworkKey }}')"
                                type="button"
                                @class([
                                    'p-4 border rounded-lg text-left transition-all',
                                    'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500' => $framework === $frameworkKey,
                                    'border-gray-200 hover:border-gray-300 hover:bg-gray-50' => $framework !== $frameworkKey,
                                ])
                            >
                                <div class="font-medium text-gray-900">{{ $frameworkLabel }}</div>
                            </button>
                        @endforeach
                    </div>
                    @error('framework')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <!-- Domain -->
            <div class="mb-6">
                <label for="primaryDomain" class="block text-sm font-medium text-gray-700 mb-2">
                    Custom Domain (optional)
                </label>
                <input
                    type="text"
                    id="primaryDomain"
                    wire:model="primaryDomain"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="app.example.com"
                >
                @error('primaryDomain')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500">
                    Leave empty to use a generated subdomain (e.g., {{ $name }}.dply.cloud)
                </p>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between">
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
                    Configure Resources
                </button>
            </div>
        </div>
    @endif

    <!-- Step 3: Resources -->
    @if ($step === 3)
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Resource Configuration</h2>

            <!-- CPU Limit -->
            <div class="mb-6">
                <label for="cpuLimit" class="block text-sm font-medium text-gray-700 mb-2">
                    CPU Limit: {{ $cpuLimit }}m ({{ number_format($cpuLimit / 1000, 1) }} vCPU)
                </label>
                <input
                    type="range"
                    id="cpuLimit"
                    wire:model="cpuLimit"
                    min="100"
                    max="4000"
                    step="100"
                    class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
                >
                <div class="flex justify-between text-xs text-gray-500 mt-1">
                    <span>0.1 vCPU</span>
                    <span>4 vCPU</span>
                </div>
                @error('cpuLimit')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Memory Limit -->
            <div class="mb-6">
                <label for="memoryLimit" class="block text-sm font-medium text-gray-700 mb-2">
                    Memory Limit: {{ $memoryLimit }} MB
                </label>
                <input
                    type="range"
                    id="memoryLimit"
                    wire:model="memoryLimit"
                    min="128"
                    max="8192"
                    step="128"
                    class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
                >
                <div class="flex justify-between text-xs text-gray-500 mt-1">
                    <span>128 MB</span>
                    <span>8 GB</span>
                </div>
                @error('memoryLimit')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <!-- Replicas -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
                <div>
                    <label for="minReplicas" class="block text-sm font-medium text-gray-700 mb-2">
                        Min Replicas: {{ $minReplicas }}
                    </label>
                    <input
                        type="range"
                        id="minReplicas"
                        wire:model="minReplicas"
                        min="1"
                        max="10"
                        step="1"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
                    >
                    @error('minReplicas')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="maxReplicas" class="block text-sm font-medium text-gray-700 mb-2">
                        Max Replicas: {{ $maxReplicas }}
                    </label>
                    <input
                        type="range"
                        id="maxReplicas"
                        wire:model="maxReplicas"
                        min="1"
                        max="50"
                        step="1"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
                    >
                    @error('maxReplicas')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Summary -->
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Configuration Summary</h3>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Runtime</dt>
                        <dd class="font-medium text-gray-900">{{ $this->availableRuntimes[$runtime] ?? $runtime }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Framework</dt>
                        <dd class="font-medium text-gray-900">{{ $this->availableFrameworks[$framework] ?? ($framework ?: 'Generic') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Resources per Pod</dt>
                        <dd class="font-medium text-gray-900">{{ number_format($cpuLimit / 1000, 1) }} vCPU / {{ $memoryLimit }} MB</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Scaling</dt>
                        <dd class="font-medium text-gray-900">{{ $minReplicas }}-{{ $maxReplicas }} pods</dd>
                    </div>
                </dl>
            </div>

            <!-- Navigation -->
            <div class="flex justify-between">
                <button
                    wire:click="previousStep"
                    type="button"
                    class="px-6 py-2 text-gray-700 font-medium hover:text-gray-900"
                >
                    Back
                </button>
                <button
                    wire:click="createApp"
                    type="button"
                    class="px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Create Application
                </button>
            </div>
        </div>
    @endif
</div>
