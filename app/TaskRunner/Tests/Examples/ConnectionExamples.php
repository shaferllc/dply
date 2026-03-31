<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\ConnectionManager;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\TaskChain;

/**
 * Examples demonstrating the new connection features.
 */
class ConnectionExamples
{
    /**
     * Example using connections from database.
     */
    public function databaseConnectionsExample(): void
    {
        echo "=== Database Connections Example ===\n";

        // Create a task
        $task = AnonymousTask::command('Check Server Status', 'uptime');

        // Dispatch to servers from database table
        $results = TaskRunner::dispatchToDatabaseServers(
            $task,
            'servers',
            ['status' => 'active', 'environment' => 'production'],
            ['name' => 'asc']
        );

        echo 'Dispatched to '.count($results['results'])." servers\n";
        echo "Successful: {$results['successful_servers']}\n";
        echo "Failed: {$results['failed_servers']}\n";
    }

    /**
     * Example using connections from model query.
     */
    public function modelConnectionsExample(): void
    {
        echo "=== Model Connections Example ===\n";

        $task = AnonymousTask::command('Check Disk Space', 'df -h');

        // Dispatch to servers using Eloquent model
        $results = TaskRunner::dispatchToModelServers(
            $task,
            'App\\Modules\\Servers\\Models\\Server',
            ['active' => true],
            ['created_at' => 'desc']
        );

        echo 'Dispatched to '.count($results['results'])." servers\n";
        echo "Success rate: {$results['success_rate']}%\n";
    }

    /**
     * Example using connections from arrays.
     */
    public function arrayConnectionsExample(): void
    {
        echo "=== Array Connections Example ===\n";

        $task = AnonymousTask::command('Check Memory', 'free -h');

        // Define connections as arrays
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

        echo 'Dispatched to '.count($results['results'])." servers\n";
        foreach ($results['results'] as $connection => $result) {
            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
            echo "  {$connection}: {$status}\n";
        }
    }

    /**
     * Example using connections from config names.
     */
    public function configConnectionsExample(): void
    {
        echo "=== Config Connections Example ===\n";

        $task = AnonymousTask::command('Check Load Average', 'uptime');

        // Use connection names from config
        $connectionNames = ['production-server-1', 'production-server-2', 'staging-server'];

        $results = TaskRunner::dispatchToMultipleConnections($task, $connectionNames);

        echo 'Dispatched to '.count($results['results'])." servers\n";
        echo 'Overall success: '.($results['overall_success'] ? 'YES' : 'NO')."\n";
    }

    /**
     * Example using connections from database IDs.
     */
    public function databaseIdConnectionsExample(): void
    {
        echo "=== Database ID Connections Example ===\n";

        $task = AnonymousTask::command('Check Network', 'netstat -tuln');

        // Use database table:ID format
        $connectionIds = ['servers:1', 'servers:5', 'servers:12'];

        $results = TaskRunner::dispatchToMultipleConnections($task, $connectionIds);

        echo 'Dispatched to '.count($results['results'])." servers\n";
        foreach ($results['results'] as $connection => $result) {
            $output = substr($result['output'], 0, 100);
            echo "  {$connection}: {$output}...\n";
        }
    }

    /**
     * Example using connections from groups.
     */
    public function groupConnectionsExample(): void
    {
        echo "=== Group Connections Example ===\n";

        $task = AnonymousTask::command('Update System', 'apt update && apt upgrade -y');

        // Dispatch to servers in a specific group
        $results = TaskRunner::dispatchToGroup(
            $task,
            'web-servers',
            'servers',
            ['parallel' => true, 'timeout' => 300]
        );

        echo "Dispatched to web-servers group\n";
        echo "Total servers: {$results['total_servers']}\n";
        echo "Successful: {$results['successful_servers']}\n";
    }

    /**
     * Example using connections from tags.
     */
    public function taggedConnectionsExample(): void
    {
        echo "=== Tagged Connections Example ===\n";

        $task = AnonymousTask::command('Restart Services', 'systemctl restart nginx php-fpm');

        // Dispatch to servers with specific tags
        $results = TaskRunner::dispatchToTaggedServers(
            $task,
            ['web', 'production'],
            'servers',
            ['parallel' => true, 'stop_on_failure' => false]
        );

        echo "Dispatched to servers with tags: web, production\n";
        echo "Success rate: {$results['success_rate']}%\n";
    }

    /**
     * Example using connections from environment variables.
     */
    public function environmentConnectionsExample(): void
    {
        echo "=== Environment Connections Example ===\n";

        $task = AnonymousTask::command('Check Logs', 'tail -n 50 /var/log/nginx/error.log');

        // Dispatch to servers defined in environment variables
        $results = TaskRunner::dispatchToEnvironmentServers(
            $task,
            ['SSH_', 'DEPLOY_'],
            ['parallel' => false, 'timeout' => 60]
        );

        echo "Dispatched to environment-configured servers\n";
        echo "Total servers: {$results['total_servers']}\n";
    }

    /**
     * Example using connections from JSON file.
     */
    public function jsonFileConnectionsExample(): void
    {
        echo "=== JSON File Connections Example ===\n";

        $task = AnonymousTask::command('Backup Database', 'mysqldump --all-databases > backup.sql');

        // Create a sample JSON file
        $jsonFile = storage_path('app/servers.json');
        $this->createSampleJsonFile($jsonFile);

        $results = TaskRunner::dispatchToJsonFileServers(
            $task,
            $jsonFile,
            ['parallel' => true, 'min_success' => 2]
        );

        echo "Dispatched to servers from JSON file\n";
        echo 'Overall success: '.($results['overall_success'] ? 'YES' : 'NO')."\n";

        // Clean up
        unlink($jsonFile);
    }

    /**
     * Example using connections from CSV file.
     */
    public function csvFileConnectionsExample(): void
    {
        echo "=== CSV File Connections Example ===\n";

        $task = AnonymousTask::command('Check SSL Certificates', 'openssl x509 -checkend 86400 -noout -in /etc/ssl/certs/ssl-cert.pem');

        // Create a sample CSV file
        $csvFile = storage_path('app/servers.csv');
        $this->createSampleCsvFile($csvFile);

        $columnMapping = [
            'host' => 'server_host',
            'port' => 'ssh_port',
            'username' => 'ssh_user',
            'private_key' => 'ssh_key',
        ];

        $results = TaskRunner::dispatchToCsvFileServers(
            $task,
            $csvFile,
            $columnMapping,
            ['parallel' => false, 'timeout' => 120]
        );

        echo "Dispatched to servers from CSV file\n";
        echo "Total servers: {$results['total_servers']}\n";

        // Clean up
        unlink($csvFile);
    }

    /**
     * Example using mixed connection types.
     */
    public function mixedConnectionsExample(): void
    {
        echo "=== Mixed Connections Example ===\n";

        $task = AnonymousTask::command('System Info', 'uname -a && cat /etc/os-release');

        // Mix different connection types
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

        echo "Dispatched to mixed connection types\n";
        echo "Successful: {$results['successful_servers']}\n";
        echo "Failed: {$results['failed_servers']}\n";
    }

    /**
     * Example using task chains with multiple connections.
     */
    public function taskChainConnectionsExample(): void
    {
        echo "=== Task Chain Connections Example ===\n";

        // Create a task chain
        $chain = TaskChain::make()
            ->add(AnonymousTask::command('Update System', 'apt update'))
            ->add(AnonymousTask::command('Upgrade Packages', 'apt upgrade -y'))
            ->add(AnonymousTask::command('Clean Up', 'apt autoremove -y'));

        // Get connections from database
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromQuery('servers', ['environment' => 'staging']);

        echo 'Running task chain on '.$connections->count()." staging servers\n";

        // Run the chain on each server
        foreach ($connections as $connection) {
            echo "Running chain on {$connection}...\n";
            $results = TaskRunner::runChain($chain);
            echo 'Chain completed with '.count($results)." tasks\n";
        }
    }

    /**
     * Example using connection validation.
     */
    public function connectionValidationExample(): void
    {
        echo "=== Connection Validation Example ===\n";

        $connectionManager = app(ConnectionManager::class);

        // Test various connection sources
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

        echo "Validation Results:\n";
        echo "  Total sources: {$validation['total']}\n";
        echo "  Valid sources: {$validation['valid_count']}\n";
        echo "  Errors: {$validation['error_count']}\n";

        if (! empty($validation['errors'])) {
            echo "  Error details:\n";
            foreach ($validation['errors'] as $error) {
                echo "    Source {$error['index']}: {$error['error']}\n";
            }
        }
    }

    /**
     * Create a sample JSON file for testing.
     */
    private function createSampleJsonFile(string $filePath): void
    {
        $data = [
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

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Create a sample CSV file for testing.
     */
    private function createSampleCsvFile(string $filePath): void
    {
        $data = [
            ['server_host', 'ssh_port', 'ssh_user', 'ssh_key'],
            ['server1.example.com', '22', 'root', '-----BEGIN OPENSSH PRIVATE KEY-----'],
            ['server2.example.com', '2222', 'deploy', '-----BEGIN OPENSSH PRIVATE KEY-----'],
        ];

        $handle = fopen($filePath, 'w');
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    /**
     * Run all connection examples.
     */
    public function runAllExamples(): void
    {
        echo "=== Connection Examples ===\n\n";

        try {
            $this->arrayConnectionsExample();
            echo "\n";

            $this->configConnectionsExample();
            echo "\n";

            $this->databaseIdConnectionsExample();
            echo "\n";

            $this->groupConnectionsExample();
            echo "\n";

            $this->taggedConnectionsExample();
            echo "\n";

            $this->mixedConnectionsExample();
            echo "\n";

            $this->taskChainConnectionsExample();
            echo "\n";

            $this->connectionValidationExample();
            echo "\n";

        } catch (\Exception $e) {
            echo 'Example failed: '.$e->getMessage()."\n";
        }

        echo "=== All Connection Examples Completed ===\n";
    }
}
