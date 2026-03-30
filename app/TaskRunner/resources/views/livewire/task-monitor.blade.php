<div class="task-monitor bg-white rounded-lg shadow-lg p-6" wire:poll.10s>
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-900">Task Monitor</h2>
            <p class="text-gray-600">
                @if($taskId)
                    Monitoring Task: <span class="font-mono text-sm">{{ $taskId }}</span>
                @else
                    No task selected
                @endif
            </p>
        </div>
        
        <div class="flex items-center space-x-4">
            <!-- Status Indicator -->
            <div class="flex items-center">
                <div class="w-3 h-3 rounded-full {{ $isRunning ? 'bg-green-500 animate-pulse' : 'bg-gray-400' }} mr-2"></div>
                <span class="text-sm font-medium {{ $isRunning ? 'text-green-600' : 'text-gray-600' }}">
                    {{ $isRunning ? 'Running' : 'Stopped' }}
                </span>
            </div>
            
            <!-- Controls -->
            <div class="flex items-center space-x-2">
                <button 
                    wire:click="toggleAutoScroll" 
                    class="px-3 py-1 text-sm rounded {{ $autoScroll ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700' }}"
                >
                    {{ $autoScroll ? 'Auto-scroll ON' : 'Auto-scroll OFF' }}
                </button>
                
                <button 
                    wire:click="clearLogs" 
                    class="px-3 py-1 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200"
                >
                    Clear Logs
                </button>
                
                <button 
                    wire:click="exportLogs" 
                    class="px-3 py-1 text-sm bg-green-100 text-green-700 rounded hover:bg-green-200"
                >
                    Export
                </button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-4 flex items-center space-x-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Log Level</label>
            <select wire:model.live="filterLevel" class="text-sm border border-gray-300 rounded px-2 py-1">
                <option value="all">All Levels</option>
                <option value="debug">Debug</option>
                <option value="info">Info</option>
                <option value="warning">Warning</option>
                <option value="error">Error</option>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Log Type</label>
            <select wire:model.live="filterType" class="text-sm border border-gray-300 rounded px-2 py-1">
                <option value="all">All Types</option>
                <option value="process_output">Process Output</option>
                <option value="task_event">Task Events</option>
                <option value="error">Errors</option>
                <option value="progress">Progress</option>
                <option value="general">General</option>
            </select>
        </div>
        
        <!-- Stats -->
        <div class="ml-auto flex items-center space-x-4 text-sm text-gray-600">
            <span>Total: {{ $logStats['total'] }}</span>
            @foreach($logStats['levels'] as $level => $count)
                <span class="px-2 py-1 rounded text-xs {{ $this->getLogLevelClass($level) }}">
                    {{ ucfirst($level) }}: {{ $count }}
                </span>
            @endforeach
        </div>
    </div>

    <!-- Log Display -->
    <div class="bg-gray-50 rounded-lg p-4 h-96 overflow-y-auto" id="log-container">
        @if(empty($filteredLogs))
            <div class="text-center text-gray-500 py-8">
                <div class="text-4xl mb-2">📝</div>
                <p>No logs to display</p>
                @if($taskId)
                    <p class="text-sm">Waiting for task output...</p>
                @else
                    <p class="text-sm">Select a task to start monitoring</p>
                @endif
            </div>
        @else
            <div class="space-y-2">
                @foreach($filteredLogs as $log)
                    <div class="log-entry p-3 rounded border {{ $this->getLogTypeClass($log['type']) }}" 
                         x-data="{ expanded: false }">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-3 flex-1">
                                <!-- Icon -->
                                <span class="text-lg">{{ $this->getLogLevelIcon($log['level']) }}</span>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="text-xs font-mono text-gray-500">
                                            {{ \Carbon\Carbon::parse($log['timestamp'])->format('H:i:s') }}
                                        </span>
                                        <span class="text-xs px-2 py-1 rounded {{ $this->getLogLevelClass($log['level']) }} bg-white">
                                            {{ strtoupper($log['level']) }}
                                        </span>
                                        <span class="text-xs px-2 py-1 rounded bg-gray-200 text-gray-700">
                                            {{ $log['type'] }}
                                        </span>
                                    </div>
                                    
                                    <div class="text-sm font-mono text-gray-800 break-words">
                                        {{ $log['message'] }}
                                    </div>
                                    
                                    @if(!empty($log['context']) && count($log['context']) > 1)
                                        <button 
                                            @click="expanded = !expanded"
                                            class="text-xs text-blue-600 hover:text-blue-800 mt-1"
                                        >
                                            {{ $expanded ? 'Hide' : 'Show' }} context
                                        </button>
                                        
                                        <div x-show="expanded" x-collapse class="mt-2">
                                            <pre class="text-xs bg-gray-100 p-2 rounded overflow-x-auto">{{ json_encode($log['context'], JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Task Result -->
    @if($lastResult)
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <h3 class="text-lg font-semibold mb-2">Task Result</h3>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="font-medium">Exit Code:</span>
                    <span class="font-mono {{ $lastResult->isSuccessful() ? 'text-green-600' : 'text-red-600' }}">
                        {{ $lastResult->getExitCode() }}
                    </span>
                </div>
                <div>
                    <span class="font-medium">Status:</span>
                    <span class="{{ $lastResult->isSuccessful() ? 'text-green-600' : 'text-red-600' }}">
                        {{ $lastResult->isSuccessful() ? 'Success' : 'Failed' }}
                    </span>
                </div>
                @if($lastResult->isTimeout())
                    <div class="col-span-2">
                        <span class="font-medium text-yellow-600">⚠️ Task timed out</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Auto-scroll behavior -->
    @if($autoScroll)
        <script>
            document.addEventListener('livewire:init', () => {
                const logContainer = document.getElementById('log-container');
                
                Livewire.on('log-added', () => {
                    logContainer.scrollTop = logContainer.scrollHeight;
                });
            });
        </script>
    @endif
</div> 