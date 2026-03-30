<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task List - TaskRunner</title>
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
                        <h1 class="text-2xl font-bold text-gray-900">Task List</h1>
                        <span class="ml-3 px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">v2.0</span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="/tasks" class="text-blue-600 hover:text-blue-800">Dashboard</a>
                        <a href="/tasks/execute" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">New Task</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            
            <!-- Filters and Search -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="searchInput" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input 
                            type="text" 
                            id="searchInput"
                            placeholder="Search tasks..."
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                    
                    <div>
                        <label for="statusFilter" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select 
                            id="statusFilter"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="running">Running</option>
                            <option value="finished">Finished</option>
                            <option value="failed">Failed</option>
                            <option value="timeout">Timeout</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="timeFilter" class="block text-sm font-medium text-gray-700 mb-1">Time Range</label>
                        <select 
                            id="timeFilter"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">All Time</option>
                            <option value="1">Last Hour</option>
                            <option value="24">Last 24 Hours</option>
                            <option value="168">Last Week</option>
                            <option value="720">Last Month</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button 
                            onclick="applyFilters()"
                            class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            🔍 Apply Filters
                        </button>
                    </div>
                </div>
            </div>

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
                        <h2 class="text-lg font-semibold text-gray-900">Tasks</h2>
                        <div class="flex items-center space-x-2">
                            <button 
                                onclick="refreshTasks()"
                                class="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
                            >
                                🔄 Refresh
                            </button>
                            <button 
                                onclick="exportTasks()"
                                class="px-3 py-1 text-sm bg-green-100 text-green-700 rounded hover:bg-green-200"
                            >
                                📥 Export
                            </button>
                            <button 
                                onclick="clearCompleted()"
                                class="px-3 py-1 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200"
                            >
                                🗑️ Clear Completed
                            </button>
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
                                    Exit Code
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
                
                <!-- Pagination -->
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Showing <span id="showingStart">0</span> to <span id="showingEnd">0</span> of <span id="totalCount">0</span> tasks
                        </div>
                        <div class="flex items-center space-x-2">
                            <button 
                                id="prevPage"
                                onclick="changePage(-1)"
                                class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
                            >
                                Previous
                            </button>
                            <span class="text-sm text-gray-700">
                                Page <span id="currentPage">1</span> of <span id="totalPages">1</span>
                            </span>
                            <button 
                                id="nextPage"
                                onclick="changePage(1)"
                                class="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @livewireScripts
    
    <script>
        let currentPage = 1;
        let totalPages = 1;
        let currentFilters = {};

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadTasks();
            
            // Auto-refresh every 30 seconds
            setInterval(loadTasks, 30000);
        });

        // Load tasks
        async function loadTasks() {
            try {
                const params = new URLSearchParams({
                    page: currentPage,
                    per_page: 20,
                    ...currentFilters
                });
                
                const response = await fetch(`/api/tasks?${params}`);
                const result = await response.json();
                
                if (response.ok) {
                    displayTasks(result.data);
                    updatePagination(result.pagination);
                    updateStats(result.data);
                }
            } catch (error) {
                console.error('Error loading tasks:', error);
            }
        }

        // Display tasks
        function displayTasks(tasks) {
            const tbody = document.getElementById('taskList');
            tbody.innerHTML = '';

            if (tasks.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                            <div class="text-4xl mb-2">📝</div>
                            <p>No tasks found</p>
                            <p class="text-sm">Try adjusting your filters or create a new task</p>
                        </td>
                    </tr>
                `;
                return;
            }

            tasks.forEach(task => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50';
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10">
                                <div class="h-10 w-10 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <span class="text-gray-600 text-sm">📋</span>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">${task.name}</div>
                                <div class="text-sm text-gray-500">${task.id}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusClass(task.status)}">
                            ${task.status}
                        </span>
                        ${task.status === 'running' ? '<div class="w-2 h-2 bg-green-500 rounded-full animate-pulse mt-1"></div>' : ''}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${task.started_at ? new Date(task.started_at).toLocaleString() : '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${task.duration ? formatDuration(task.duration) : '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <span class="font-mono ${task.exit_code === 0 ? 'text-green-600' : 'text-red-600'}">
                            ${task.exit_code !== null ? task.exit_code : '-'}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div class="flex items-center space-x-2">
                            <button 
                                onclick="viewTask('${task.id}')" 
                                class="text-blue-600 hover:text-blue-900"
                                title="View Details"
                            >
                                👁️
                            </button>
                            <button 
                                onclick="viewOutput('${task.id}')" 
                                class="text-green-600 hover:text-green-900"
                                title="View Output"
                            >
                                📄
                            </button>
                            ${task.status === 'running' ? `
                            <button 
                                onclick="cancelTask('${task.id}')" 
                                class="text-red-600 hover:text-red-900"
                                title="Cancel Task"
                            >
                                ⏹️
                            </button>
                            ` : ''}
                            <button 
                                onclick="deleteTask('${task.id}')" 
                                class="text-red-600 hover:text-red-900"
                                title="Delete Task"
                            >
                                🗑️
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Status styling
        function getStatusClass(status) {
            switch (status) {
                case 'running': return 'bg-green-100 text-green-800';
                case 'finished': return 'bg-blue-100 text-blue-800';
                case 'failed': return 'bg-red-100 text-red-800';
                case 'timeout': return 'bg-yellow-100 text-yellow-800';
                case 'cancelled': return 'bg-gray-100 text-gray-800';
                case 'pending': return 'bg-purple-100 text-purple-800';
                default: return 'bg-gray-100 text-gray-800';
            }
        }

        // Format duration
        function formatDuration(seconds) {
            if (seconds < 60) return `${seconds}s`;
            if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            return `${hours}h ${minutes}m`;
        }

        // Update pagination
        function updatePagination(pagination) {
            currentPage = pagination.current_page;
            totalPages = pagination.last_page;
            
            document.getElementById('currentPage').textContent = currentPage;
            document.getElementById('totalPages').textContent = totalPages;
            document.getElementById('showingStart').textContent = ((currentPage - 1) * pagination.per_page) + 1;
            document.getElementById('showingEnd').textContent = Math.min(currentPage * pagination.per_page, pagination.total);
            document.getElementById('totalCount').textContent = pagination.total;
            
            document.getElementById('prevPage').disabled = currentPage <= 1;
            document.getElementById('nextPage').disabled = currentPage >= totalPages;
        }

        // Update statistics
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

        // Apply filters
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const timeRange = document.getElementById('timeFilter').value;
            
            currentFilters = {};
            
            if (search) currentFilters.name = search;
            if (status) currentFilters.status = status;
            if (timeRange) currentFilters.recent = timeRange;
            
            currentPage = 1;
            loadTasks();
        }

        // Pagination
        function changePage(delta) {
            const newPage = currentPage + delta;
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                loadTasks();
            }
        }

        // Task actions
        function viewTask(taskId) {
            window.open(`/tasks/id/${taskId}`, '_blank');
        }

        function viewOutput(taskId) {
            window.open(`/tasks/id/${taskId}?tab=output`, '_blank');
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
                    loadTasks();
                } else {
                    alert('Error cancelling task');
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        async function deleteTask(taskId) {
            if (!confirm('Are you sure you want to delete this task? This action cannot be undone.')) return;

            try {
                const response = await fetch(`/api/tasks/${taskId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                });

                if (response.ok) {
                    alert('Task deleted successfully!');
                    loadTasks();
                } else {
                    alert('Error deleting task');
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        // Utility functions
        function refreshTasks() {
            loadTasks();
        }

        function exportTasks() {
            const params = new URLSearchParams({
                ...currentFilters,
                export: 'true'
            });
            
            window.open(`/api/tasks?${params}`, '_blank');
        }

        async function clearCompleted() {
            if (!confirm('Are you sure you want to clear all completed tasks? This action cannot be undone.')) return;

            try {
                const response = await fetch('/api/tasks/clear-completed', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                });

                if (response.ok) {
                    alert('Completed tasks cleared successfully!');
                    loadTasks();
                } else {
                    alert('Error clearing completed tasks');
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        // Search functionality
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    </script>
</body>
</html> 