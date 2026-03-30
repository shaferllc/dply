<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Exceptions\ConnectionNotFoundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Connection Manager for handling multiple connection sources and types.
 */
class ConnectionManager
{
    /**
     * The connection cache.
     */
    protected array $connectionCache = [];

    /**
     * Create a connection from various sources.
     */
    public function createConnection(mixed $source): Connection
    {
        if ($source instanceof Connection) {
            return $source;
        }

        if (is_string($source)) {
            return $this->createFromString($source);
        }

        if (is_array($source)) {
            return $this->createFromArray($source);
        }

        if ($source instanceof Model) {
            return $this->createFromModel($source);
        }

        throw new InvalidArgumentException('Invalid connection source type: '.gettype($source));
    }

    /**
     * Create multiple connections from various sources.
     */
    public function createConnections(mixed $sources): Collection
    {
        if ($sources instanceof Collection) {
            $sources = $sources->toArray();
        }

        if (! is_array($sources)) {
            $sources = [$sources];
        }

        return collect($sources)->map(fn ($source) => $this->createConnection($source));
    }

    /**
     * Create a connection from a string (config name or connection string).
     */
    protected function createFromString(string $source): Connection
    {
        // Check if it's a config connection name
        if (config("task-runner.connections.{$source}")) {
            return Connection::fromConfig($source);
        }

        // Check if it's a connection string (user@host:port)
        if (preg_match('/^([^@]+)@([^:]+)(?::(\d+))?$/', $source, $matches)) {
            return $this->createFromConnectionString($matches);
        }

        // Check if it's a database connection string (table:id)
        if (str_contains($source, ':')) {
            [$table, $id] = explode(':', $source, 2);

            return $this->createFromDatabase($table, $id);
        }

        throw new ConnectionNotFoundException("Connection not found: {$source}");
    }

    /**
     * Create a connection from an array.
     */
    protected function createFromArray(array $source): Connection
    {
        return Connection::fromArray($source);
    }

    /**
     * Create a connection from a database model.
     */
    protected function createFromModel(Model $model): Connection
    {
        $attributes = $model->toArray();

        // Map common server model attributes to connection attributes
        $connectionData = [
            'host' => $attributes['host'] ?? $attributes['ip_address'] ?? $attributes['address'] ?? '',
            'port' => $attributes['port'] ?? $attributes['ssh_port'] ?? 22,
            'username' => $attributes['username'] ?? $attributes['ssh_user'] ?? 'root',
            'private_key' => $attributes['private_key'] ?? $attributes['ssh_key'] ?? null,
            'private_key_path' => $attributes['private_key_path'] ?? null,
            'script_path' => $attributes['script_path'] ?? $attributes['working_directory'] ?? null,
            'proxy_jump' => $attributes['proxy_jump'] ?? $attributes['jump_host'] ?? null,
        ];

        return Connection::fromArray($connectionData);
    }

    /**
     * Create a connection from database table and ID.
     */
    protected function createFromDatabase(string $table, string $id): Connection
    {
        $cacheKey = "{$table}:{$id}";

        if (isset($this->connectionCache[$cacheKey])) {
            return $this->connectionCache[$cacheKey];
        }

        $model = DB::table($table)->find($id);

        if (! $model) {
            throw new ConnectionNotFoundException("Connection not found in {$table} with ID {$id}");
        }

        $connection = $this->createFromArray((array) $model);
        $this->connectionCache[$cacheKey] = $connection;

        return $connection;
    }

    /**
     * Create a connection from a connection string.
     */
    protected function createFromConnectionString(array $matches): Connection
    {
        $username = $matches[1];
        $host = $matches[2];
        $port = isset($matches[3]) ? (int) $matches[3] : 22;

        return new Connection(
            host: $host,
            port: $port,
            username: $username,
            privateKey: null, // Will need to be provided separately
            scriptPath: null, // Will use default
        );
    }

    /**
     * Create connections from a database query.
     */
    public function createFromQuery(string $table, array $where = [], array $orderBy = []): Collection
    {
        $query = DB::table($table);

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        $models = $query->get();

        return $models->map(fn ($model) => $this->createFromArray((array) $model));
    }

    /**
     * Create connections from a model query.
     */
    public function createFromModelQuery(string $modelClass, array $where = [], array $orderBy = []): Collection
    {
        $query = $modelClass::query();

        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        $models = $query->get();

        return $models->map(fn ($model) => $this->createFromModel($model));
    }

    /**
     * Create connections from a server group or tag.
     */
    public function createFromGroup(string $groupName, string $table = 'servers'): Collection
    {
        return $this->createFromQuery($table, ['group' => $groupName]);
    }

    /**
     * Create connections from tags.
     */
    public function createFromTags(array $tags, string $table = 'servers'): Collection
    {
        $query = DB::table($table);

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        $models = $query->get();

        return $models->map(fn ($model) => $this->createFromArray((array) $model));
    }

    /**
     * Create connections from environment variables.
     */
    public function createFromEnvironment(array $prefixes = ['SSH_']): Collection
    {
        $connections = [];
        $envVars = $_ENV;

        foreach ($envVars as $key => $value) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $connectionKey = strtolower(str_replace($prefix, '', $key));
                    $connections[$connectionKey] = $value;
                }
            }
        }

        if (empty($connections)) {
            return collect();
        }

        // Group by connection (assuming format like SSH_HOST_1, SSH_USER_1, etc.)
        $groupedConnections = [];
        foreach ($connections as $key => $value) {
            $parts = explode('_', $key);
            $connectionId = end($parts);
            $property = implode('_', array_slice($parts, 0, -1));

            $groupedConnections[$connectionId][$property] = $value;
        }

        return collect($groupedConnections)->map(fn ($connection) => $this->createFromArray($connection));
    }

    /**
     * Create connections from a JSON file.
     */
    public function createFromJsonFile(string $filePath): Collection
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("JSON file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new InvalidArgumentException("Failed to read JSON file: {$filePath}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON in file: {$filePath}");
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON file must contain an array of connections');
        }

        return $this->createConnections($data);
    }

    /**
     * Create connections from a CSV file.
     */
    public function createFromCsvFile(string $filePath, array $columnMapping = []): Collection
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("CSV file not found: {$filePath}");
        }

        $defaultMapping = [
            'host' => 'host',
            'port' => 'port',
            'username' => 'username',
            'private_key' => 'private_key',
            'script_path' => 'script_path',
            'proxy_jump' => 'proxy_jump',
        ];

        $mapping = array_merge($defaultMapping, $columnMapping);
        $connections = [];

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Failed to open CSV file: {$filePath}");
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new InvalidArgumentException('Failed to read CSV headers');
        }

        while (($row = fgetcsv($handle)) !== false) {
            $connectionData = [];
            foreach ($mapping as $connectionKey => $csvColumn) {
                $columnIndex = array_search($csvColumn, $headers);
                if ($columnIndex !== false && isset($row[$columnIndex])) {
                    $connectionData[$connectionKey] = $row[$columnIndex];
                }
            }

            if (! empty($connectionData)) {
                $connections[] = $connectionData;
            }
        }

        fclose($handle);

        return $this->createConnections($connections);
    }

    /**
     * Clear the connection cache.
     */
    public function clearCache(): void
    {
        $this->connectionCache = [];
    }

    /**
     * Get cache statistics.
     */
    public function getCacheStats(): array
    {
        return [
            'cached_connections' => count($this->connectionCache),
            'cache_keys' => array_keys($this->connectionCache),
        ];
    }

    /**
     * Validate connection sources before creating them.
     */
    public function validateSources(mixed $sources): array
    {
        $errors = [];
        $validSources = [];

        if ($sources instanceof Collection) {
            $sources = $sources->toArray();
        }

        if (! is_array($sources)) {
            $sources = [$sources];
        }

        foreach ($sources as $index => $source) {
            try {
                $this->createConnection($source);
                $validSources[] = $source;
            } catch (Throwable $e) {
                $errors[] = [
                    'index' => $index,
                    'source' => $source,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'valid' => $validSources,
            'errors' => $errors,
            'total' => count($sources),
            'valid_count' => count($validSources),
            'error_count' => count($errors),
        ];
    }
}
