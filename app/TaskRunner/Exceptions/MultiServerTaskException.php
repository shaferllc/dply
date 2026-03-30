<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

class MultiServerTaskException extends Exception
{
    /**
     * The multi-server task ID.
     */
    protected string $multiServerTaskId;

    /**
     * The server connections.
     */
    protected array $connections;

    /**
     * The failed connections.
     */
    protected array $failedConnections;

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = '',
        string $multiServerTaskId = '',
        array $connections = [],
        array $failedConnections = [],
        array $summary = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);

        $this->multiServerTaskId = $multiServerTaskId;
        $this->connections = $connections;
        $this->failedConnections = $failedConnections;
    }

    /**
     * Get the multi-server task ID.
     */
    public function getMultiServerTaskId(): string
    {
        return $this->multiServerTaskId;
    }

    /**
     * Get the server connections.
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Get the failed connections.
     */
    public function getFailedConnections(): array
    {
        return $this->failedConnections;
    }

    /**
     * Get the successful connections.
     */
    public function getSuccessfulConnections(): array
    {
        return array_diff($this->connections, $this->failedConnections);
    }

    /**
     * Get the number of total servers.
     */
    public function getTotalServers(): int
    {
        return count($this->connections);
    }

    /**
     * Get the number of failed servers.
     */
    public function getFailedServers(): int
    {
        return count($this->failedConnections);
    }

    /**
     * Get the number of successful servers.
     */
    public function getSuccessfulServers(): int
    {
        return count($this->getSuccessfulConnections());
    }

    /**
     * Get the success rate percentage.
     */
    public function getSuccessRate(): float
    {
        $total = $this->getTotalServers();
        if ($total === 0) {
            return 0.0;
        }

        return ($this->getSuccessfulServers() / $total) * 100;
    }
}
