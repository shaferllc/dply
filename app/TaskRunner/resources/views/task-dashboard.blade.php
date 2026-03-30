<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaskRunner Dashboard</title>
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
                        <h1 class="text-2xl font-bold text-gray-900">TaskRunner Dashboard</h1>
                        <span class="ml-3 px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">v2.0</span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-500">Manage and execute bash scripts</span>
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Task Creation Panel -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Create New Task</h2>
                        
                        <!-- Quick Commands -->
                        <div class="mb-6">
                            <h3 class="text-sm font-medium text-gray-700 mb-3">Quick Commands</h3>
                            <div class="space-y-2">
                                <button 
                                    onclick="setQuickCommand('ls -la')"
                                    class="w-full text-left px-3 py-2 text-sm bg-gray-50 hover:bg-gray-100 rounded border"
                                >
                                    📁 List files
                                </button>
                                <button 
                                    onclick="setQuickCommand('df -h')"
                                    class="w-full text-left px-3 py-2 text-sm bg-gray-50 hover:bg-gray-100 rounded border"
                                >
                                    💾 Disk usage
                                </button>
                                <button 
                                    onclick="setQuickCommand('ps aux | head -10')"
                                    class="w-full text-left px-3 py-2 text-sm bg-gray-50 hover:bg-gray-100 rounded border"
                                >
                                    🔄 Process list
                                </button>
                                <button 
                                    onclick="setQuickCommand('systemctl status')"
                                    class="w-full text-left px-3 py-2 text-sm bg-gray-50 hover:bg-gray-100 rounded border"
                                >
                                    ⚙️ System status
                                </button>
                            </div>
                        </div>

                        <!-- Task Form -->
                        <form id="taskForm" class="space-y-4">
                            <div>
                                <label for="taskName" class="block text-sm font-medium text-gray-700 mb-1">
                                    Task Name
                                </label>
                                <input 
                                    type="text" 
                                    id="taskName" 
                                    name="name"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="e.g., Database Backup"
                                >
                            </div>

                            <div>
                                <label for="taskCommand" class="block text-sm font-medium text-gray-700 mb-1">
                                    Bash Command
                                </label>
                                <textarea 
                                    id="taskCommand" 
                                    name="command"
                                    rows="4"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                    placeholder="Enter your bash command here..."
                                ></textarea>
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
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="300"
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
                                    class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    🚀 Run Task
                                </button>
                                <button 
                                    type="button"
                                    onclick="saveTask()"
                                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    💾 Save
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Task Templates -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Task Templates</h3>
                        <div class="space-y-3">
                            <button 
                                onclick="loadTemplate('backup')"
                                class="w-full text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Database Backup</div>
                                <div class="text-sm text-gray-500">pg_dump with compression</div>
                            </button>
                            
                            <button 
                                onclick="loadTemplate('deployment')"
                                class="w-full text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">Laravel Deployment</div>
                                <div class="text-sm text-gray-500">Git pull, composer, migrations</div>
                            </button>
                            
                            <button 
                                onclick="loadTemplate('monitoring')"
                                class="w-full text-left p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="font-medium text-gray-900">System Monitoring</div>
                                <div class="text-sm text-gray-500">CPU, memory, disk usage</div>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Task List and Monitoring -->
                <div class="lg:col-span-2">
                    <!-- Task Statistics -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <span class="text-blue-600 text-sm font-medium">📊</span>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-500">Total Tasks</div>
                                    <div class="text-lg font-semibold text-gray-900" id="totalTasks">0</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                                        <span class="text-green-600 text-sm font-medium">✅</span>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-500">Running</div>
                                    <div class="text-lg font-semibold text-gray-900" id="runningTasks">0</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                                        <span class="text-yellow-600 text-sm font-medium">⏰</span>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-500">Success Rate</div>
                                    <div class="text-lg font-semibold text-gray-900" id="successRate">0%</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                                        <span class="text-red-600 text-sm font-medium">❌</span>
                                    </div>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-500">Failed</div>
                                    <div class="text-lg font-semibold text-gray-900" id="failedTasks">0</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Task List -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900">Recent Tasks</h3>
                                <div class="flex items-center space-x-2">
                                    <button 
                                        onclick="refreshTasks()"
                                        class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
                                    >
                                        🔄 Refresh
                                    </button>
                                    <select 
                                        id="statusFilter"
                                        onchange="filterTasks()"
                                        class="text-sm border border-gray-300 rounded px-2 py-1"
                                    >
                                        <option value="">All Status</option>
                                        <option value="running">Running</option>
                                        <option value="finished">Finished</option>
                                        <option value="failed">Failed</option>
                                        <option value="timeout">Timeout</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Task
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Started
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Duration
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody id="taskList" class="bg-white divide-y divide-gray-200">
                                    <!-- Tasks will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Task Monitor -->
                    <div class="mt-6">
                        @livewire('task-runner::task-monitor')
                    </div>
                </div>
            </div>
        </div>
    </div>

    @livewireScripts
    
    <script>
        // Task templates
        const templates = {
            backup: {
                name: 'Database Backup',
                command: `#!/bin/bash
# Database backup script
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"
echo "Starting database backup..."

# Create backup
pg_dump production_db > $BACKUP_FILE

# Compress backup
gzip $BACKUP_FILE

# Upload to S3
aws s3 cp $BACKUP_FILE.gz s3://backups/

echo "Backup completed: $BACKUP_FILE.gz"`,
                timeout: 1800
            },
            deployment: {
                name: 'Laravel Deployment',
                command: `#!/bin/bash
# Laravel deployment script
echo "Starting Laravel deployment..."

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php artisan migrate --force

# Clear caches
php artisan cache:clear
php artisan config:clear

# Health check
php artisan health:check

echo "Deployment completed successfully!"`,
                timeout: 600
            },
            monitoring: {
                name: 'System Monitoring',
                command: `#!/bin/bash
# System monitoring script
echo "=== System Status Report ==="
echo "Date: $(date)"
echo ""

echo "=== CPU Usage ==="
top -bn1 | grep "Cpu(s)" | awk '{print $2}' | cut -d'%' -f1

echo "=== Memory Usage ==="
free -h | grep Mem

echo "=== Disk Usage ==="
df -h | grep -E '^/dev/'

echo "=== Load Average ==="
uptime | awk -F'load average:' '{print $2}'`,
                timeout: 300
            }
        };

        // Quick command functions
        function setQuickCommand(command) {
            document.getElementById('taskCommand').value = command;
            document.getElementById('taskName').focus();
        }

        function loadTemplate(templateName) {
            const template = templates[templateName];
            if (template) {
                document.getElementById('taskName').value = template.name;
                document.getElementById('taskCommand').value = template.command;
                document.getElementById('taskTimeout').value = template.timeout;
            }
        }

        // Task form submission
        document.getElementById('taskForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = {
                command: formData.get('command'),
                name: formData.get('name') || formData.get('command'),
                timeout: formData.get('timeout') ? parseInt(formData.get('timeout')) : null,
                connection: formData.get('connection') || null,
                callback_url: formData.get('callback_url') || null,
                background: formData.get('background') === 'on',
                data: formData.get('data') ? JSON.parse(formData.get('data')) : {}
            };

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
                    alert('Task started successfully!');
                    refreshTasks();
                } else {
                    alert('Error: ' + (result.error || 'Failed to start task'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });

        // Task management functions
        async function refreshTasks() {
            try {
                const response = await fetch('/api/tasks?per_page=20');
                const result = await response.json();
                
                if (response.ok) {
                    displayTasks(result.data);
                    updateStats(result.data);
                }
            } catch (error) {
                console.error('Error refreshing tasks:', error);
            }
        }

        function displayTasks(tasks) {
            const tbody = document.getElementById('taskList');
            tbody.innerHTML = '';

            tasks.forEach(task => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">${task.name}</div>
                        <div class="text-sm text-gray-500">${task.id}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusClass(task.status)}">
                            ${task.status}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${task.started_at ? new Date(task.started_at).toLocaleString() : '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${task.duration ? task.duration + 's' : '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="viewTask('${task.id}')" class="text-blue-600 hover:text-blue-900 mr-2">View</button>
                        <button onclick="cancelTask('${task.id}')" class="text-red-600 hover:text-red-900">Cancel</button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        function getStatusClass(status) {
            switch (status) {
                case 'running': return 'bg-green-100 text-green-800';
                case 'finished': return 'bg-blue-100 text-blue-800';
                case 'failed': return 'bg-red-100 text-red-800';
                case 'timeout': return 'bg-yellow-100 text-yellow-800';
                default: return 'bg-gray-100 text-gray-800';
            }
        }

        function updateStats(tasks) {
            const total = tasks.length;
            const running = tasks.filter(t => t.status === 'running').length;
            const failed = tasks.filter(t => t.status === 'failed').length;
            const successRate = total > 0 ? Math.round(((total - failed) / total) * 100) : 0;

            document.getElementById('totalTasks').textContent = total;
            document.getElementById('runningTasks').textContent = running;
            document.getElementById('failedTasks').textContent = failed;
            document.getElementById('successRate').textContent = successRate + '%';
        }

        function filterTasks() {
            const status = document.getElementById('statusFilter').value;
            const url = status ? `/api/tasks?status=${status}` : '/api/tasks';
            
            fetch(url)
                .then(response => response.json())
                .then(result => {
                    if (result.data) {
                        displayTasks(result.data);
                    }
                })
                .catch(error => console.error('Error filtering tasks:', error));
        }

        async function viewTask(taskId) {
            window.open(`/tasks/id/${taskId}`, '_blank');
        }

        async function cancelTask(taskId) {
            if (!confirm('Are you sure you want to cancel this task?')) return;

            try {
                const response = await fetch(`/api/tasks/${taskId}/cancel`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                });

                if (response.ok) {
                    alert('Task cancelled successfully!');
                    refreshTasks();
                } else {
                    alert('Error cancelling task');
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function saveTask() {
            const name = document.getElementById('taskName').value;
            const command = document.getElementById('taskCommand').value;
            
            if (!name || !command) {
                alert('Please fill in task name and command');
                return;
            }

            // Save to localStorage for now (could be enhanced with backend storage)
            const savedTasks = JSON.parse(localStorage.getItem('savedTasks') || '[]');
            savedTasks.push({ name, command, timestamp: new Date().toISOString() });
            localStorage.setItem('savedTasks', JSON.stringify(savedTasks));
            
            alert('Task saved successfully!');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            refreshTasks();
            
            // Auto-refresh every 30 seconds
            setInterval(refreshTasks, 30000);
        });
    </script>
</body>
</html> 