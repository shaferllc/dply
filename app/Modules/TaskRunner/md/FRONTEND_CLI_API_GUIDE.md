# TaskRunner Frontend, CLI, and API Guide

This guide covers the comprehensive interfaces for TaskRunner: a beautiful frontend monitor, powerful CLI commands, and a complete REST API.

## Frontend Task Monitor

The TaskRunner includes a real-time task monitoring interface built with Livewire and Tailwind CSS.

### Accessing the Monitor

```bash
# View all tasks
http://your-app.com/tasks

# View tasks by name
http://your-app.com/tasks/name/backup-script

# View a specific task by ID
http://your-app.com/tasks/id/123
```

### Features

- **Real-time Updates**: Auto-refreshing task status and output
- **Progress Tracking**: Visual progress bars for running tasks
- **Output Streaming**: Live output display with auto-scroll
- **Error Handling**: Dedicated error display sections
- **Task Filtering**: View all tasks, by name, or by ID
- **Responsive Design**: Works on desktop and mobile devices

### Including in Your Views

```php
// Include the task monitor in any Blade view
@livewire('task-monitor', [
    'taskName' => 'backup-script',
    'taskId' => null,
    'showAllTasks' => false
])

// Or include it for all tasks
@livewire('task-monitor', [
    'showAllTasks' => true
])
```

### Customization

The monitor uses Tailwind CSS classes and can be easily customized:

```blade
<!-- Custom styling -->
<div class="task-monitor bg-gray-900 text-white">
    @livewire('task-monitor')
</div>
```

## CLI Commands

TaskRunner provides powerful CLI commands for task management and execution.

### Task Listing

```bash
# List all tasks
php artisan task:list

# List with filters
php artisan task:list --name="backup" --status="running" --limit=20

# Show only running tasks
php artisan task:list --running

# Show only failed tasks
php artisan task:list --failed

# Show recent tasks (last 24 hours)
php artisan task:list --recent

# Output in different formats
php artisan task:list --format=json --verbose
php artisan task:list --format=csv
```

### Task Details

```bash
# Show task details by ID
php artisan task:show 123

# Show task details by name
php artisan task:show "backup-script"

# Show with output
php artisan task:show 123 --output

# Show with error
php artisan task:show 123 --error

# Follow task in real-time
php artisan task:show 123 --follow

# Output in JSON format
php artisan task:show 123 --format=json
```

### Task Execution

```bash
# Run a simple command
php artisan task:run "ls -la"

# Run with custom name and timeout
php artisan task:run "backup-database" --name="Database Backup" --timeout=300

# Run on specific connection
php artisan task:run "deploy" --connection="production-server"

# Run in parallel mode
php artisan task:run "system-check" --parallel --max-concurrency=5

# Create a task chain
php artisan task:run "apt update; apt upgrade; apt autoremove" --chain

# Run a view task
php artisan task:run "status-report" --view="emails.status" --data="env=production"

# Follow output in real-time
php artisan task:run "long-running-script" --follow

# Suppress output (useful for scripts)
php artisan task:run "cleanup" --quiet
```

### Advanced CLI Examples

```bash
# Parallel system monitoring
php artisan task:run "system-check" --parallel --max-concurrency=4 --name="System Monitor"

# Database backup chain
php artisan task:run "mysqldump --all-databases; gzip backup.sql; upload-to-s3" --chain --name="Database Backup"

# View task with data
php artisan task:run "report" --view="reports.daily" --data="date=2024-01-15" --data="user=admin"

# Follow multiple tasks
php artisan task:run "deploy-frontend; deploy-backend; run-tests" --chain --follow

# Quiet execution for scripts
php artisan task:run "cleanup-logs" --quiet && echo "Cleanup completed"
```

## REST API

TaskRunner provides a complete REST API for programmatic task management.

### Base URL

```
https://your-app.com/api/tasks
```

### Authentication

The API uses Laravel's built-in authentication. Include your API token in the Authorization header:

```
Authorization: Bearer your-api-token
```

### Endpoints

#### List Tasks

```http
GET /api/tasks
```

**Query Parameters:**
- `name` - Filter by task name
- `status` - Filter by status (pending, running, finished, failed, etc.)
- `running` - Show only running tasks
- `failed` - Show only failed tasks
- `recent` - Show tasks from last N hours
- `sort_by` - Sort field (created_at, name, status, etc.)
- `sort_order` - Sort order (asc, desc)
- `per_page` - Items per page (max 100)

**Example:**
```bash
curl -X GET "https://your-app.com/api/tasks?status=running&per_page=20" \
  -H "Authorization: Bearer your-token"
```

#### Get Task Statistics

```http
GET /api/tasks/stats
```

**Response:**
```json
{
  "data": {
    "total": 1250,
    "running": 5,
    "completed": 1200,
    "failed": 45,
    "pending": 0,
    "recent_24h": 25,
    "recent_7d": 150,
    "avg_duration": 45.2,
    "success_rate": 96.4
  }
}
```

#### Search Tasks

```http
GET /api/tasks/search?q=backup
```

#### Get Tasks by Status

```http
GET /api/tasks/status/running
```

#### Show Task Details

```http
GET /api/tasks/{task}
```

**Example:**
```bash
curl -X GET "https://your-app.com/api/tasks/123" \
  -H "Authorization: Bearer your-token"
```

#### Run Single Task

```http
POST /api/tasks/run
```

**Request Body:**
```json
{
  "command": "ls -la /var/www",
  "name": "List Web Directory",
  "timeout": 60,
  "connection": "web-server"
}
```

**Response:**
```json
{
  "data": {
    "name": "List Web Directory",
    "successful": true,
    "exit_code": 0,
    "output": "total 1234\ndrwxr-xr-x 2 www-data www-data 4096 Jan 15 10:00 .\n...",
    "timeout": false
  }
}
```

#### Run View Task

```http
POST /api/tasks/run
```

**Request Body:**
```json
{
  "view": "emails.status-report",
  "name": "Status Report",
  "data": {
    "environment": "production",
    "date": "2024-01-15"
  }
}
```

#### Run Parallel Tasks

```http
POST /api/tasks/run/parallel
```

**Request Body:**
```json
{
  "tasks": [
    {
      "command": "systemctl status nginx",
      "name": "Check Nginx"
    },
    {
      "command": "systemctl status mysql",
      "name": "Check MySQL"
    },
    {
      "command": "systemctl status redis",
      "name": "Check Redis"
    }
  ],
  "max_concurrency": 3,
  "timeout": 120,
  "stop_on_failure": false,
  "min_success": 2
}
```

**Response:**
```json
{
  "data": {
    "execution_id": "parallel_1234567890",
    "total_tasks": 3,
    "successful_tasks": 3,
    "failed_tasks": 0,
    "success_rate": 100.0,
    "max_concurrency": 3,
    "started_at": "2024-01-15T10:00:00Z",
    "completed_at": "2024-01-15T10:00:05Z",
    "duration": 5.2,
    "results": [
      {
        "task_name": "Check Nginx",
        "success": true,
        "exit_code": 0,
        "duration": 1.2
      }
    ],
    "overall_success": true
  }
}
```

#### Run Task Chain

```http
POST /api/tasks/run/chain
```

**Request Body:**
```json
{
  "tasks": [
    {
      "command": "git pull origin main",
      "name": "Pull Latest Code"
    },
    {
      "command": "composer install --no-dev",
      "name": "Install Dependencies"
    },
    {
      "command": "php artisan migrate",
      "name": "Run Migrations"
    },
    {
      "command": "php artisan config:cache",
      "name": "Cache Configuration"
    }
  ],
  "parallel": false,
  "timeout": 300,
  "stop_on_failure": true
}
```

#### Stream Task Output

```http
GET /api/tasks/{task}/stream
```

**Response:**
```json
{
  "data": {
    "task_id": "123",
    "name": "Long Running Task",
    "status": "running",
    "output": "Processing item 1...\nProcessing item 2...",
    "error": null,
    "progress": 45,
    "is_running": true
  }
}
```

#### Cancel Task

```http
POST /api/tasks/{task}/cancel
```

**Response:**
```json
{
  "data": {
    "task_id": "123",
    "status": "cancelled",
    "message": "Task cancelled successfully"
  }
}
```

#### Delete Task

```http
DELETE /api/tasks/{task}
```

**Response:**
```json
{
  "data": {
    "message": "Task deleted successfully"
  }
}
```

### API Error Handling

All API endpoints return appropriate HTTP status codes:

- `200` - Success
- `400` - Bad Request (validation errors)
- `404` - Task not found
- `422` - Validation errors
- `500` - Server error

**Error Response Format:**
```json
{
  "error": "Task not found",
  "errors": {
    "command": ["The command field is required."]
  }
}
```

### API Rate Limiting

The API includes rate limiting to prevent abuse:

- 60 requests per minute per user
- 1000 requests per hour per user

### Webhook Integration

You can integrate TaskRunner with external services using webhooks:

```php
// In your application
$task = AnonymousTask::command('deploy', 'deploy.sh');
$task->onSuccess(function () {
    // Send webhook to Slack
    Http::post('https://hooks.slack.com/...', [
        'text' => 'Deployment completed successfully!'
    ]);
});
```

## Integration Examples

### Frontend Integration

```javascript
// Fetch tasks using the API
async function fetchTasks() {
    const response = await fetch('/api/tasks?status=running', {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        }
    });
    return response.json();
}

// Run a task
async function runTask(command, name) {
    const response = await fetch('/api/tasks/run', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ command, name })
    });
    return response.json();
}
```

### CLI Integration in Scripts

```bash
#!/bin/bash

# Deploy script using TaskRunner
echo "Starting deployment..."

# Run database backup
php artisan task:run "mysqldump --all-databases > backup.sql" --name="Database Backup" --quiet

# Deploy code
php artisan task:run "git pull origin main" --name="Pull Code" --quiet

# Install dependencies
php artisan task:run "composer install --no-dev" --name="Install Dependencies" --quiet

# Run migrations
php artisan task:run "php artisan migrate --force" --name="Run Migrations" --quiet

# Clear caches
php artisan task:run "php artisan config:cache && php artisan route:cache" --chain --name="Clear Caches" --quiet

echo "Deployment completed!"
```

### Monitoring Integration

```php
// Monitor task health
Route::get('/health/tasks', function () {
    $stats = Http::get('/api/tasks/stats')->json();
    
    if ($stats['data']['running'] > 10) {
        return response()->json(['status' => 'warning', 'message' => 'Too many running tasks']);
    }
    
    if ($stats['data']['success_rate'] < 90) {
        return response()->json(['status' => 'error', 'message' => 'Low success rate']);
    }
    
    return response()->json(['status' => 'healthy']);
});
```

This comprehensive interface system provides maximum flexibility for integrating TaskRunner into your workflows, whether through the web interface, command line, or programmatic API access. 