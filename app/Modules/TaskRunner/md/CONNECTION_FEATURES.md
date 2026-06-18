# TaskRunner Connection Features

This document explains the enhanced connection management features in TaskRunner, allowing you to pass connection information from various sources including databases, arrays, and multiple connection types.

## Overview

TaskRunner now supports multiple connection sources and types, making it easy to manage and dispatch tasks to various servers and environments. The new `ConnectionManager` class provides a unified interface for creating connections from different sources.

## Connection Sources

### 1. Database Connections

#### From Database Table
```php
use App\Modules\TaskRunner\Facades\TaskRunner;

$task = AnonymousTask::command('Check Status', 'uptime');

// Dispatch to servers from database table
$results = TaskRunner::dispatchToDatabaseServers(
    $task,
    'servers',
    ['status' => 'active', 'environment' => 'production'],
    ['name' => 'asc']
);
```

#### From Database ID
```php
// Use table:ID format
$connectionIds = ['servers:1', 'servers:5', 'servers:12'];

$results = TaskRunner::dispatchToMultipleConnections($task, $connectionIds);
```

#### From Eloquent Model Query
```php
$results = TaskRunner::dispatchToModelServers(
    $task,
    'App\\Models\\Server',
    ['active' => true],
    ['created_at' => 'desc']
);
```

### 2. Array Connections

```php
$connections = [
    [
        'host' => 'server1.example.com',
        'port' => 22,
        'username' => 'root',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
        'script_path' => '/root/.dply-task-runner',
    ],
    [
        'host' => 'server2.example.com',
        'port' => 2222,
        'username' => 'deploy',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
        'script_path' => '/home/deploy/.dply-task-runner',
    ],
];

$results = TaskRunner::dispatchToMultipleConnections($task, $connections);
```

### 3. Configuration Connections

```php
// Use connection names from config/dply.php
$connectionNames = ['production-server-1', 'production-server-2', 'staging-server'];

$results = TaskRunner::dispatchToMultipleConnections($task, $connectionNames);
```

### 4. Connection Objects

```php
use App\Modules\TaskRunner\Connection;

$connection = new Connection(
    host: 'server.example.com',
    port: 22,
    username: 'root',
    privateKey: '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
    scriptPath: '/root/.dply-task-runner',
);

$results = TaskRunner::dispatchToMultipleConnections($task, [$connection]);
```

### 5. Group-based Connections

```php
// Dispatch to servers in a specific group
$results = TaskRunner::dispatchToGroup(
    $task,
    'web-servers',
    'servers',
    ['parallel' => true, 'timeout' => 300]
);
```

### 6. Tag-based Connections

```php
// Dispatch to servers with specific tags
$results = TaskRunner::dispatchToTaggedServers(
    $task,
    ['web', 'production'],
    'servers',
    ['parallel' => true, 'stop_on_failure' => false]
);
```

### 7. Environment Variable Connections

```php
// Dispatch to servers defined in environment variables
$results = TaskRunner::dispatchToEnvironmentServers(
    $task,
    ['SSH_', 'DEPLOY_'],
    ['parallel' => false, 'timeout' => 60]
);
```

### 8. File-based Connections

#### JSON File
```php
$results = TaskRunner::dispatchToJsonFileServers(
    $task,
    storage_path('app/servers.json'),
    ['parallel' => true, 'min_success' => 2]
);
```

#### CSV File
```php
$columnMapping = [
    'host' => 'server_host',
    'port' => 'ssh_port',
    'username' => 'ssh_user',
    'private_key' => 'ssh_key',
];

$results = TaskRunner::dispatchToCsvFileServers(
    $task,
    storage_path('app/servers.csv'),
    $columnMapping,
    ['parallel' => false, 'timeout' => 120]
);
```

## Connection Manager

The `ConnectionManager` class provides direct access to connection creation methods:

```php
use App\Modules\TaskRunner\ConnectionManager;

$connectionManager = app(ConnectionManager::class);

// Create single connection
$connection = $connectionManager->createConnection('servers:1');

// Create multiple connections
$connections = $connectionManager->createConnections([
    'servers:1',
    'servers:2',
    ['host' => 'server3.example.com', 'port' => 22, 'username' => 'root'],
]);

// Create from database query
$connections = $connectionManager->createFromQuery(
    'servers',
    ['environment' => 'production'],
    ['name' => 'asc']
);

// Create from model query
$connections = $connectionManager->createFromModelQuery(
    'App\\Models\\Server',
    ['active' => true],
    ['created_at' => 'desc']
);

// Create from group
$connections = $connectionManager->createFromGroup('web-servers', 'servers');

// Create from tags
$connections = $connectionManager->createFromTags(['web', 'production'], 'servers');

// Create from environment variables
$connections = $connectionManager->createFromEnvironment(['SSH_']);

// Create from JSON file
$connections = $connectionManager->createFromJsonFile(storage_path('app/servers.json'));

// Create from CSV file
$connections = $connectionManager->createFromCsvFile(
    storage_path('app/servers.csv'),
    ['host' => 'server_host', 'port' => 'ssh_port']
);
```

## Mixed Connection Types

You can mix different connection types in a single dispatch:

```php
$mixedConnections = [
    'production-server-1', // Config name
    'servers:5', // Database ID
    [ // Array
        'host' => 'backup.example.com',
        'port' => 22,
        'username' => 'backup',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
    ],
    new Connection( // Connection object
        host: 'monitoring.example.com',
        port: 22,
        username: 'monitor',
        privateKey: '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
        scriptPath: '/home/monitor/.dply-task-runner',
    ),
];

$results = TaskRunner::dispatchToMultipleConnections(
    $task,
    $mixedConnections,
    ['parallel' => true, 'max_failures' => 1]
);
```

## Connection Validation

Validate connection sources before creating them:

```php
$connectionManager = app(ConnectionManager::class);

$testSources = [
    'production-server-1', // Valid config
    'servers:999', // Invalid database ID
    [ // Valid array
        'host' => 'test.example.com',
        'port' => 22,
        'username' => 'test',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\n...',
    ],
    [ // Invalid array (missing host)
        'port' => 22,
        'username' => 'test',
    ],
];

$validation = $connectionManager->validateSources($testSources);

echo "Total sources: {$validation['total']}\n";
echo "Valid sources: {$validation['valid_count']}\n";
echo "Errors: {$validation['error_count']}\n";
```

## Multi-Server Options

All multi-server dispatch methods support the following options:

```php
$options = [
    'parallel' => true,           // Execute in parallel (default: true)
    'timeout' => 300,             // Timeout in seconds
    'stop_on_failure' => false,   // Stop on first failure (default: false)
    'wait_for_all' => true,       // Wait for all servers (default: true)
    'min_success' => 2,           // Minimum successful servers required
    'max_failures' => 1,          // Maximum allowed failures
];
```

## Task Chains with Multiple Connections

Run task chains on multiple servers:

```php
use App\Modules\TaskRunner\TaskChain;

// Create a task chain
$chain = TaskChain::make()
    ->add(AnonymousTask::command('Update System', 'apt update'))
    ->add(AnonymousTask::command('Upgrade Packages', 'apt upgrade -y'))
    ->add(AnonymousTask::command('Clean Up', 'apt autoremove -y'));

// Get connections from database
$connectionManager = app(ConnectionManager::class);
$connections = $connectionManager->createFromQuery('servers', ['environment' => 'staging']);

// Run the chain on each server
foreach ($connections as $connection) {
    $results = TaskRunner::runChain($chain);
    echo "Chain completed with " . count($results) . " tasks\n";
}
```

## Connection Caching

The ConnectionManager includes caching to improve performance:

```php
$connectionManager = app(ConnectionManager::class);

// Get cache statistics
$stats = $connectionManager->getCacheStats();
echo "Cached connections: {$stats['cached_connections']}\n";

// Clear cache
$connectionManager->clearCache();
```

## Database Schema Requirements

For database-based connections, your servers table should include these columns:

```sql
CREATE TABLE servers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 22,
    username VARCHAR(255) DEFAULT 'root',
    private_key TEXT,
    private_key_path VARCHAR(255),
    script_path VARCHAR(255),
    proxy_jump VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    environment VARCHAR(50),
    group_name VARCHAR(100),
    tags JSON,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

## Environment Variables Format

For environment-based connections, use this format:

```bash
# Server 1
SSH_HOST_1=server1.example.com
SSH_PORT_1=22
SSH_USER_1=root
SSH_PRIVATE_KEY_1=-----BEGIN OPENSSH PRIVATE KEY-----\n...

# Server 2
SSH_HOST_2=server2.example.com
SSH_PORT_2=2222
SSH_USER_2=deploy
SSH_PRIVATE_KEY_2=-----BEGIN OPENSSH PRIVATE KEY-----\n...
```

## JSON File Format

```json
[
    {
        "host": "server1.example.com",
        "port": 22,
        "username": "root",
        "private_key": "-----BEGIN OPENSSH PRIVATE KEY-----\n...",
        "script_path": "/root/.dply-task-runner"
    },
    {
        "host": "server2.example.com",
        "port": 2222,
        "username": "deploy",
        "private_key": "-----BEGIN OPENSSH PRIVATE KEY-----\n...",
        "script_path": "/home/deploy/.dply-task-runner"
    }
]
```

## CSV File Format

```csv
server_host,ssh_port,ssh_user,ssh_key,script_path
server1.example.com,22,root,"-----BEGIN OPENSSH PRIVATE KEY-----",/root/.dply-task-runner
server2.example.com,2222,deploy,"-----BEGIN OPENSSH PRIVATE KEY-----",/home/deploy/.dply-task-runner
```

## Error Handling

All connection methods include comprehensive error handling:

```php
try {
    $results = TaskRunner::dispatchToDatabaseServers($task, 'servers');
} catch (ConnectionNotFoundException $e) {
    echo "Connection not found: " . $e->getMessage();
} catch (InvalidArgumentException $e) {
    echo "Invalid connection data: " . $e->getMessage();
} catch (MultiServerTaskException $e) {
    echo "Multi-server task failed: " . $e->getMessage();
}
```

## Performance Considerations

- **Caching**: Connection objects are cached to avoid repeated database queries
- **Parallel Execution**: Use `parallel => true` for better performance with multiple servers
- **Connection Pooling**: The system efficiently manages connection resources
- **Timeout Management**: Set appropriate timeouts to prevent hanging tasks

## Security Features

- **Private Key Validation**: Ensures proper SSH key format
- **Path Validation**: Prevents path traversal attacks
- **Input Sanitization**: All connection parameters are validated
- **Secure Storage**: Private keys can be stored encrypted in the database

This enhanced connection system provides maximum flexibility while maintaining security and performance standards. 