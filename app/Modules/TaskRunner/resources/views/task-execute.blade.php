<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Execute Task - TaskRunner</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    @livewireStyles
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center py-4">
                    <div class="flex items-center">
                        <a href="/tasks" class="text-blue-600 hover:text-blue-800 mr-4">
                            ← Back to Dashboard
                        </a>
                        <h1 class="text-2xl font-bold text-gray-900">Execute Task</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-500">Run bash scripts with real-time monitoring</span>
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <!-- Task Configuration -->
                <div class="space-y-6">
                    <!-- Command Input -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Task Configuration</h2>
                        
                        <form id="executeForm" class="space-y-4">
                            <div>
                                <label for="taskName" class="block text-sm font-medium text-gray-700 mb-1">
                                    Task Name
                                </label>
                                <input 
                                    type="text" 
                                    id="taskName" 
                                    name="name"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="e.g., System Check"
                                    value="{{ request('name', '') }}"
                                >
                            </div>

                            <div>
                                <label for="taskCommand" class="block text-sm font-medium text-gray-700 mb-1">
                                    Bash Command
                                </label>
                                <textarea 
                                    id="taskCommand" 
                                    name="command"
                                    rows="8"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                    placeholder="Enter your bash command here..."
                                >{{ request('command', '') }}</textarea>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="taskTimeout" class="block text-sm font-medium text-gray-700 mb-1">
                                        Timeout (seconds)
                                    </label>
                                    <input 
                                        type="number" 
                                        id="taskTimeout" 
                                        name="timeout"
                                        min="1"
                                        max="3600"
                                        value="300"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                </div>
                                <div>
                                    <label for="taskConnection" class="block text-sm font-medium text-gray-700 mb-1">
                                        Connection
                                    </label>
                                    <select 
                                        id="taskConnection" 
                                        name="connection"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="">Local</option>
                                        <option value="production">Production</option>
                                        <option value="staging">Staging</option>
                                        <option value="development">Development</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Advanced Options -->
                            <div x-data="{ showAdvanced: false }">
                                <button 
                                    type="button"
                                    @click="showAdvanced = !showAdvanced"
                                    class="text-sm text-blue-600 hover:text-blue-800"
                                >
                                    {{ showAdvanced ? 'Hide' : 'Show' }} Advanced Options
                                </button>
                                
                                <div x-show="showAdvanced" x-collapse class="mt-4 space-y-4">
                                    <div>
                                        <label class="flex items-center">
                                            <input type="checkbox" name="background" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="ml-2 text-sm text-gray-700">Run in background</span>
                                        </label>
                                    </div>
                                    
                                    <div>
                                        <label for="callbackUrl" class="block text-sm font-medium text-gray-700 mb-1">
                                            Callback URL
                                        </label>
                                        <input 
                                            type="url" 
                                            id="callbackUrl" 
                                            name="callback_url"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                            placeholder="https://api.example.com/webhooks/task"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="taskData" class="block text-sm font-medium text-gray-700 mb-1">
                                            Additional Data (JSON)
                                        </label>
                                        <textarea 
                                            id="taskData" 
                                            name="data"
                                            rows="3"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                            placeholder='{"environment": "production", "user": "admin"}'
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="flex space-x-3">
                                <button 
                                    type="submit"
                                    id="executeBtn"
                                    class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    🚀 Execute Task
                                </button>
                                <button 
                                    type="button"
                                    onclick="clearForm()"
                                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    🗑️ Clear
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Quick Commands -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Commands</h3>
                        <div class="grid grid-cols-1 gap-2">
                            <button 
                                onclick="setQuickCommand('System Info', 'uname -a && cat /etc/os-release')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">System Information</div>
                                <div class="text-sm text-gray-500">OS details and kernel info</div>
                            </button>
                            
                            <button 
                                onclick="setQuickCommand('Disk Usage', 'df -h && du -sh /* 2>/dev/null | head -10')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Disk Usage</div>
                                <div class="text-sm text-gray-500">Filesystem and directory sizes</div>
                            </button>
                            
                            <button 
                                onclick="setQuickCommand('Process List', 'ps aux --sort=-%cpu | head -15')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Process List</div>
                                <div class="text-sm text-gray-500">Top CPU-consuming processes</div>
                            </button>
                            
                            <button 
                                onclick="setQuickCommand('Network Status', 'netstat -tuln && ss -tuln')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Network Status</div>
                                <div class="text-sm text-gray-500">Open ports and connections</div>
                            </button>
                            
                            <button 
                                onclick="setQuickCommand('Service Status', 'systemctl list-units --type=service --state=running | head -20')"
                                class="text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Service Status</div>
                                <div class="text-sm text-gray-500">Running system services</div>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Task Execution and Output -->
                <div class="space-y-6">
                    <!-- Execution Status -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Execution Status</h2>
                        
                        <div id="executionStatus" class="space-y-4">
                            <div class="text-center text-gray-500 py-8">
                                <div class="text-4xl mb-2">⏳</div>
                                <p>Ready to execute</p>
                                <p class="text-sm">Configure your task and click Execute</p>
                            </div>
                        </div>
                    </div>

                    <!-- Real-time Output -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-gray-900">Real-time Output</h2>
                            <div class="flex items-center space-x-2">
                                <button 
                                    id="autoScrollBtn"
                                    onclick="toggleAutoScroll()"
                                    class="px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                                >
                                    Auto-scroll ON
                                </button>
                                <button 
                                    onclick="clearOutput()"
                                    class="px-3 py-1 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200"
                                >
                                    Clear
                                </button>
                                <button 
                                    onclick="exportOutput()"
                                    class="px-3 py-1 text-sm bg-green-100 text-green-700 rounded hover:bg-green-200"
                                >
                                    Export
                                </button>
                            </div>
                        </div>
                        
                        <div 
                            id="outputContainer" 
                            class="bg-gray-900 text-green-400 font-mono text-sm p-4 rounded-lg h-96 overflow-y-auto"
                        >
                            <div class="text-gray-500">Output will appear here...</div>
                        </div>
                    </div>

                    <!-- Task Result -->
                    <div id="taskResult" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Task Result</h2>
                        <div id="resultContent" class="space-y-2">
                            <!-- Result details will be populated here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @livewireScripts

    @include('task-runner::partials.task-runner-modals')
    
    <script>
        let currentTaskId = null;
        let autoScroll = true;
        let outputBuffer = [];

        // Quick command functions
        function setQuickCommand(name, command) {
            document.getElementById('taskName').value = name;
            document.getElementById('taskCommand').value = command;
        }

        function clearForm() {
            document.getElementById('executeForm').reset();
            document.getElementById('taskName').value = '';
            document.getElementById('taskCommand').value = '';
        }

        // Task execution
        document.getElementById('executeForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = {
                command: formData.get('command'),
                name: formData.get('name') || formData.get('command'),
                timeout: formData.get('timeout') ? parseInt(formData.get('timeout')) : 300,
                connection: formData.get('connection') || null,
                callback_url: formData.get('callback_url') || null,
                background: formData.get('background') === 'on',
                data: formData.get('data') ? JSON.parse(formData.get('data')) : {}
            };

            // Validate required fields
            if (!data.command.trim()) {
                TaskRunnerModal.showAlert('Command required', 'Please enter a command to execute.', 'error');
                return;
            }

            // Update UI
            updateExecutionStatus('starting', 'Starting task execution...');
            clearOutput();
            document.getElementById('executeBtn').disabled = true;
            document.getElementById('executeBtn').textContent = '⏳ Executing...';

            try {
                const response = await fetch('/api/tasks/run', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();
                
                if (response.ok) {
                    currentTaskId = result.data?.task_id;
                    updateExecutionStatus('running', 'Task is running...');
                    addOutput('Task started successfully!', 'info');
                    addOutput(`Task ID: ${currentTaskId}`, 'info');
                    addOutput('', 'separator');
                    
                    // Start monitoring if we have a task ID
                    if (currentTaskId) {
                        startTaskMonitoring(currentTaskId);
                    }
                } else {
                    updateExecutionStatus('error', 'Failed to start task');
                    addOutput('Error: ' + (result.error || 'Failed to start task'), 'error');
                }
            } catch (error) {
                updateExecutionStatus('error', 'Network error');
                addOutput('Error: ' + error.message, 'error');
            } finally {
                document.getElementById('executeBtn').disabled = false;
                document.getElementById('executeBtn').textContent = '🚀 Execute Task';
            }
        });

        // Task monitoring
        function startTaskMonitoring(taskId) {
            // Poll for task updates
            const pollInterval = setInterval(async () => {
                try {
                    const response = await fetch(`/api/tasks/${taskId}`);
                    const result = await response.json();
                    
                    if (response.ok && result.data) {
                        const task = result.data;
                        
                        // Update status
                        updateExecutionStatus(task.status, getStatusMessage(task.status));
                        
                        // Add output if available
                        if (task.output && task.output !== outputBuffer[outputBuffer.length - 1]) {
                            addOutput(task.output, 'output');
                        }
                        
                        // Check if task is complete
                        if (['finished', 'failed', 'timeout'].includes(task.status)) {
                            clearInterval(pollInterval);
                            showTaskResult(task);
                        }
                    }
                } catch (error) {
                    console.error('Error polling task:', error);
                }
            }, 1000);
        }

        // UI update functions
        function updateExecutionStatus(status, message) {
            const statusDiv = document.getElementById('executionStatus');
            const statusClass = getStatusClass(status);
            
            statusDiv.innerHTML = `
                <div class="flex items-center space-x-3">
                    <div class="w-4 h-4 rounded-full ${statusClass}"></div>
                    <div>
                        <div class="font-medium text-gray-900">${message}</div>
                        <div class="text-sm text-gray-500">Status: ${status}</div>
                    </div>
                </div>
            `;
        }

        function getStatusClass(status) {
            switch (status) {
                case 'running': return 'bg-green-500 animate-pulse';
                case 'finished': return 'bg-blue-500';
                case 'failed': return 'bg-red-500';
                case 'timeout': return 'bg-yellow-500';
                case 'starting': return 'bg-yellow-500 animate-pulse';
                case 'error': return 'bg-red-500';
                default: return 'bg-gray-400';
            }
        }

        function getStatusMessage(status) {
            switch (status) {
                case 'running': return 'Task is currently executing...';
                case 'finished': return 'Task completed successfully!';
                case 'failed': return 'Task failed during execution';
                case 'timeout': return 'Task timed out';
                case 'starting': return 'Starting task execution...';
                case 'error': return 'Error occurred';
                default: return 'Unknown status';
            }
        }

        function addOutput(message, type = 'output') {
            const container = document.getElementById('outputContainer');
            const timestamp = new Date().toLocaleTimeString();
            
            let className = 'text-green-400';
            let prefix = '';
            
            switch (type) {
                case 'error':
                    className = 'text-red-400';
                    prefix = '[ERROR] ';
                    break;
                case 'info':
                    className = 'text-blue-400';
                    prefix = '[INFO] ';
                    break;
                case 'warning':
                    className = 'text-yellow-400';
                    prefix = '[WARN] ';
                    break;
                case 'separator':
                    className = 'text-gray-500';
                    message = '─'.repeat(50);
                    break;
                default:
                    prefix = '';
            }
            
            const outputLine = document.createElement('div');
            outputLine.className = className;
            outputLine.innerHTML = `<span class="text-gray-500">[${timestamp}]</span> ${prefix}${message}`;
            
            container.appendChild(outputLine);
            outputBuffer.push(message);
            
            if (autoScroll) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function clearOutput() {
            const container = document.getElementById('outputContainer');
            container.innerHTML = '<div class="text-gray-500">Output cleared...</div>';
            outputBuffer = [];
        }

        function toggleAutoScroll() {
            autoScroll = !autoScroll;
            const btn = document.getElementById('autoScrollBtn');
            btn.textContent = autoScroll ? 'Auto-scroll ON' : 'Auto-scroll OFF';
            btn.className = autoScroll 
                ? 'px-3 py-1 text-sm bg-blue-100 text-blue-700 rounded hover:bg-blue-200'
                : 'px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200';
        }

        function exportOutput() {
            const content = outputBuffer.join('\n');
            const blob = new Blob([content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `task-output-${new Date().toISOString().slice(0, 19)}.txt`;
            a.click();
            URL.revokeObjectURL(url);
        }

        function showTaskResult(task) {
            const resultDiv = document.getElementById('taskResult');
            const contentDiv = document.getElementById('resultContent');
            
            contentDiv.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="font-medium">Exit Code:</span>
                        <span class="font-mono ${task.exit_code === 0 ? 'text-green-600' : 'text-red-600'}">
                            ${task.exit_code || 'N/A'}
                        </span>
                    </div>
                    <div>
                        <span class="font-medium">Duration:</span>
                        <span class="font-mono">${task.duration || 0}s</span>
                    </div>
                    <div>
                        <span class="font-medium">Status:</span>
                        <span class="${task.status === 'finished' ? 'text-green-600' : 'text-red-600'}">
                            ${task.status}
                        </span>
                    </div>
                    <div>
                        <span class="font-medium">Started:</span>
                        <span>${task.started_at ? new Date(task.started_at).toLocaleString() : 'N/A'}</span>
                    </div>
                </div>
                ${task.error ? `
                <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded">
                    <div class="font-medium text-red-800">Error:</div>
                    <div class="text-red-700 font-mono text-sm">${task.error}</div>
                </div>
                ` : ''}
            `;
            
            resultDiv.classList.remove('hidden');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Check for URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const command = urlParams.get('command');
            const name = urlParams.get('name');
            
            if (command) {
                document.getElementById('taskCommand').value = command;
            }
            if (name) {
                document.getElementById('taskName').value = name;
            }
        });
    </script>
</body>
</html> 